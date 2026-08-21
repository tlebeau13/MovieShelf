<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IngestionRun;
use App\Enum\IngestionSource;
use App\Ingestion\IngestionAlreadyRunning;
use App\Ingestion\IngestionResult;
use App\Ingestion\IngestionRunRecorder;
use App\Ingestion\Nyt\NytClient;
use App\Ingestion\Nyt\NytSnapshotWriter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds `raw.nyt_snapshot` with past weeks (#6, decided in #28).
 *
 * Resumable and budget-aware because the source forces it: NYT allows 1,000
 * requests a day and one list-week costs one request, so a long range spans days.
 * Both properties come from the same place — the weeks already in the table are the
 * progress record, so a run that stops (budget spent, quota exhausted, container
 * killed) is resumed by running the exact same command again.
 */
#[AsCommand(name: 'app:ingestion:nyt:backfill', description: 'Backfill past weeks of an NYT bestseller list')]
final class NytBackfillCommand extends Command
{
    /** The first week NYT's API serves; anything earlier is a 400. */
    private const EARLIEST = '2008-06-08';

    /**
     * A year of history, per the call on #6: ~52 requests, comfortably inside one
     * day's quota. Widening it is an argument, not a code change.
     */
    private const DEFAULT_FROM = '2025-01-01';

    public function __construct(
        private readonly NytClient $client,
        private readonly NytSnapshotWriter $writer,
        private readonly IngestionRunRecorder $runs,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('list', null, InputOption::VALUE_REQUIRED, "NYT's list slug", 'hardcover-fiction')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Earliest week to fetch (Y-m-d)', self::DEFAULT_FROM)
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Latest week to fetch (Y-m-d)', 'today')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Stop after this many requests, leaving the rest for the next run', '800')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report which weeks are missing without spending any quota');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $list = (string) $input->getOption('list');
        $budget = max(1, (int) $input->getOption('budget'));

        try {
            $from = new \DateTimeImmutable((string) $input->getOption('from'));
            $to = new \DateTimeImmutable((string) $input->getOption('to'));
        } catch (\Exception $e) {
            $io->error('Could not read --from/--to as dates: '.$e->getMessage());

            return Command::INVALID;
        }

        $earliest = new \DateTimeImmutable(self::EARLIEST);
        if ($from < $earliest) {
            $io->note(\sprintf("NYT's API starts at %s; --from raised to it.", self::EARLIEST));
            $from = $earliest;
        }

        if ($to < $from) {
            $io->error('--to is before --from.');

            return Command::INVALID;
        }

        $weeks = $this->weeksBetween($from, $to);
        $stored = $this->writer->storedWeeks($list, $weeks);
        $missing = array_values(array_filter($weeks, static fn (\DateTimeImmutable $w) => !isset($stored[$w->format('Y-m-d')])));

        $io->text(\sprintf('%s: %d week(s) in range, %d already stored, %d to fetch.', $list, \count($weeks), \count($stored), \count($missing)));

        if ([] === $missing) {
            $io->success('Nothing to backfill.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->listing(array_map(static fn (\DateTimeImmutable $w) => $w->format('Y-m-d'), \array_slice($missing, 0, 20)));
            $io->note(\sprintf('Dry run: %d request(s) would be spent, %d allowed by --budget.', \count($missing), $budget));

            return Command::SUCCESS;
        }

        try {
            // The window is the whole range, not a week: one row per invocation, so
            // `app:ingestion:runs` shows a backfill as one entry rather than burying
            // the weekly runs under fifty. A single-week run has a different window,
            // so the two never collide under uniq_ingestion_run_active.
            $run = $this->runs->start(IngestionSource::Nyt, $from, $to);
        } catch (IngestionAlreadyRunning $e) {
            // Not a failure: another backfill over the same range is doing the work.
            $io->warning($e->getMessage());

            return Command::SUCCESS;
        }

        return $this->fetch($io, $run, $list, $missing, $budget);
    }

    /**
     * @param list<\DateTimeImmutable> $missing
     */
    private function fetch(SymfonyStyle $io, IngestionRun $run, string $list, array $missing, int $budget): int
    {
        $rows = 0;
        $requests = 0;
        $done = [];
        $next = null;

        foreach ($missing as $week) {
            if ($requests >= $budget) {
                // Where the next invocation picks up. The table already says this,
                // but a human reading `app:ingestion:runs` should not have to derive
                // it from a NOT IN query.
                $next = $week->format('Y-m-d');
                break;
            }

            try {
                ++$requests;
                $fetched = $this->client->fetchList($list, $week);
                $rows += $this->writer->write($run, $fetched);
                $done[] = $fetched->publishedDate->format('Y-m-d');

                $io->writeln(\sprintf(
                    '  <info>%s</info> → %d book(s) (asked for %s)',
                    $fetched->publishedDate->format('Y-m-d'),
                    \count($fetched->books),
                    $week->format('Y-m-d'),
                ));
            } catch (\Throwable $e) {
                // The weeks already written stay written — they are real rows, and
                // the next run will skip them — so the partial count goes on the
                // record rather than being lost with the exception.
                $this->runs->fail($run, $e, new IngestionResult($rows, $this->details($list, $requests, $done, $week->format('Y-m-d'))));

                $io->error(\sprintf('Stopped at %s: %s', $week->format('Y-m-d'), $e->getMessage()));
                $io->note(\sprintf('%d week(s) landed before the failure; re-run to continue from %s.', \count($done), $week->format('Y-m-d')));

                return Command::FAILURE;
            }
        }

        $this->runs->succeed($run, new IngestionResult($rows, $this->details($list, $requests, $done, $next)));

        if (null !== $next) {
            $io->warning(\sprintf('Budget of %d request(s) spent. Re-run the same command to continue from %s.', $budget, $next));
        } else {
            $io->success(\sprintf('Backfilled %d week(s), %d row(s).', \count($done), $rows));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $done
     *
     * @return array<string, mixed>
     */
    private function details(string $list, int $requests, array $done, ?string $next): array
    {
        return [
            'list' => $list,
            'requests' => $requests,
            'weeks_written' => \count($done),
            'first_week' => $done[0] ?? null,
            'last_week' => $done[array_key_last($done)] ?? null,
            'next_week' => $next,
        ];
    }

    /**
     * Every Sunday in the range, because a NYT list's `published_date` is always one.
     * A request for any other day is snapped by the API to the week's list anyway, so
     * asking on the Sunday keeps what was requested and what came back the same date.
     *
     * @return list<\DateTimeImmutable>
     */
    private function weeksBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $week = 0 === (int) $from->format('w') ? $from : $from->modify('next sunday');
        $week = $week->setTime(0, 0);
        $to = min($to, $this->clock->now())->setTime(0, 0);

        $weeks = [];
        while ($week <= $to) {
            $weeks[] = $week;
            $week = $week->modify('+7 days');
        }

        return $weeks;
    }
}
