<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IngestionRun;
use App\Enum\IngestionSource;
use App\Enum\IngestionStatus;
use App\Ingestion\IngestionRunRecorder;
use App\Repository\IngestionRunRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * "Did last night's ingestion work?" (#29). Exits non-zero when the answer is no,
 * so it can be a check rather than something a human has to read.
 */
#[AsCommand(name: 'app:ingestion:runs', description: 'Show the last ingestion runs per source')]
final class IngestionRunsCommand extends Command
{
    public function __construct(
        private readonly IngestionRunRepository $runs,
        private readonly IngestionRunRecorder $recorder,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this source ('.implode(', ', array_column(IngestionSource::cases(), 'value')).')')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many attempts to list', '20')
            ->addOption('stale-hours', null, InputOption::VALUE_REQUIRED, 'Treat a still-Running attempt older than this as a dead worker', '24')
            ->addOption('abandon-stale', null, InputOption::VALUE_NONE, 'Mark those dead attempts failed, releasing the window they hold');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $input->getOption('source');
        if (null !== $source && null === $source = IngestionSource::tryFrom($source)) {
            $io->error(\sprintf('Unknown source. Known: %s.', implode(', ', array_column(IngestionSource::cases(), 'value'))));

            return Command::INVALID;
        }

        $staleBefore = $this->clock->now()->modify(\sprintf('-%d hours', (int) $input->getOption('stale-hours')));

        if ($input->getOption('abandon-stale')) {
            // The release valve for uniq_ingestion_run_active: a dead worker's
            // orphan row holds its window until something says the run is over.
            // Manual on purpose — an orphan is evidence, and a nightly job that
            // tidied it away would erase the only trace of the crash.
            $abandoned = $this->recorder->abandonStale($staleBefore);
            $io->note(\sprintf('Abandoned %d stale run(s).', $abandoned));
        }

        $runs = $this->runs->findRecent($source, max(1, (int) $input->getOption('limit')));

        if ([] === $runs) {
            $io->warning('No ingestion runs recorded yet.');

            // Not a failure: before #5/#6/#7 land this is the expected state.
            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'source', 'status', 'started', 'took', 'rows', 'window', 'error'],
            array_map(fn (IngestionRun $run) => $this->row($run, $staleBefore), $runs),
        );

        return $this->verdict($io, $staleBefore, $source);
    }

    /**
     * The listing is history; the verdict is only about the most recent attempt per
     * source, because an old failure that a retry fixed is not an alert.
     */
    private function verdict(SymfonyStyle $io, \DateTimeImmutable $staleBefore, ?IngestionSource $only): int
    {
        $broken = [];
        $latest = $this->runs->findLatestPerSource();

        if (null !== $only) {
            // --source filters the verdict too, or `--source=nyt` would report red
            // because a different source is broken.
            $latest = array_intersect_key($latest, [$only->value => true]);
        }

        foreach ($latest as $name => $run) {
            if (IngestionStatus::Failed === $run->getStatus()) {
                $broken[] = \sprintf('%s failed at %s', $name, $run->getFinishedAt()?->format('Y-m-d H:i') ?? '?');
            } elseif ($this->isStale($run, $staleBefore)) {
                $broken[] = \sprintf('%s has been running since %s — the worker probably died', $name, $run->getStartedAt()->format('Y-m-d H:i'));
            }
        }

        if ([] !== $broken) {
            $io->error($broken);

            return Command::FAILURE;
        }

        $io->success(null !== $only ? \sprintf('%s last finished cleanly.', $only->value) : 'Every source last finished cleanly.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function row(IngestionRun $run, \DateTimeImmutable $staleBefore): array
    {
        $status = match (true) {
            $this->isStale($run, $staleBefore) => '<fg=red>stale</>',
            IngestionStatus::Success === $run->getStatus() => '<fg=green>success</>',
            IngestionStatus::Failed === $run->getStatus() => '<fg=red>failed</>',
            default => '<fg=yellow>running</>',
        };

        $window = null !== $run->getWindowStart()
            ? \sprintf('%s → %s', $run->getWindowStart()->format('Y-m-d'), $run->getWindowEnd()?->format('Y-m-d') ?? '?')
            : '';

        return [
            (string) $run->getId(),
            $run->getSource()->value,
            $status,
            $run->getStartedAt()->format('Y-m-d H:i:s'),
            null !== $run->getDuration() ? \sprintf('%.1fs', $run->getDuration()) : '',
            (string) $run->getRowsWritten(),
            $window,
            mb_substr($this->shortError($run), 0, 60),
        ];
    }

    /** Class without its namespace, so the message still fits in the cell. */
    private function shortError(IngestionRun $run): string
    {
        if (null === $class = $run->getErrorClass()) {
            return '';
        }

        $short = substr((string) strrchr('\\'.$class, '\\'), 1);

        return \sprintf('%s: %s', $short, (string) $run->getError());
    }

    private function isStale(IngestionRun $run, \DateTimeImmutable $staleBefore): bool
    {
        return IngestionStatus::Running === $run->getStatus() && $run->getStartedAt() < $staleBefore;
    }
}
