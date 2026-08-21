<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Entity\IngestionRun;
use App\Enum\IngestionSource;
use App\Enum\IngestionStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The one way #5, #6 and #7 record what a run did (#29).
 *
 * Use `record()` unless the job needs to report progress as it goes; `start()` +
 * `succeed()`/`fail()` is the same thing taken apart.
 */
class IngestionRunRecorder
{
    /** Long enough to identify the failure, short enough not to store a stack trace. */
    private const ERROR_MAX_LENGTH = 1000;

    /** What an abandoned run is recorded as, so orphans are countable like any other failure. */
    public const ABANDONED = 'AbandonedRun';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs $work and records the attempt whichever way it ends. The exception is
     * re-thrown after the row is written, so Messenger's retry policy (#8) still
     * sees the failure — the record is a side effect, never a swallow.
     *
     * @param callable(IngestionRun): IngestionResult $work
     *
     * @throws IngestionAlreadyRunning when another attempt at this window is in flight
     */
    public function record(
        IngestionSource $source,
        callable $work,
        ?\DateTimeImmutable $windowStart = null,
        ?\DateTimeImmutable $windowEnd = null,
    ): IngestionRun {
        $run = $this->start($source, $windowStart, $windowEnd);

        try {
            $result = $work($run);
        } catch (\Throwable $e) {
            $this->fail($run, $e);

            throw $e;
        }

        $this->succeed($run, $result);

        return $run;
    }

    /**
     * Writes the Running row immediately: a crash between here and the terminal
     * state has to leave evidence, which is exactly what a row that stays Running
     * is (see `app:ingestion:runs --stale-hours`).
     *
     * @throws IngestionAlreadyRunning
     */
    public function start(
        IngestionSource $source,
        ?\DateTimeImmutable $windowStart = null,
        ?\DateTimeImmutable $windowEnd = null,
    ): IngestionRun {
        $run = new IngestionRun($source, $this->clock->now());
        $run->setWindow($windowStart, $windowEnd);

        try {
            $this->entityManager->persist($run);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // uniq_ingestion_run_active. Translated rather than propagated: the
            // caller's decision is "skip or fail loudly", which a DBAL exception
            // does not express, and matching on SQLSTATE at three call sites would
            // be worse than one type.
            throw new IngestionAlreadyRunning($source, $windowStart, $windowEnd, $e);
        }

        return $run;
    }

    public function succeed(IngestionRun $run, IngestionResult $result): void
    {
        $run->succeed($this->clock->now(), $result->rowsWritten, $result->details);
        $this->write($run);

        $this->logger->info('Ingestion run {source} finished: {rows} rows', [
            'source' => $run->getSource()->value,
            'rows' => $result->rowsWritten,
        ]);
    }

    /**
     * $partial carries what the attempt managed to land before it threw; a failure
     * that wrote 900 of 1000 rows is still a failure, but the 900 are real and the
     * next run has to know about them.
     */
    public function fail(IngestionRun $run, \Throwable $error, ?IngestionResult $partial = null): void
    {
        $run->fail(
            $this->clock->now(),
            $error::class,
            mb_substr($error->getMessage(), 0, self::ERROR_MAX_LENGTH),
            $partial?->rowsWritten ?? 0,
            $partial?->details ?? [],
        );
        $this->write($run);

        $this->logger->error('Ingestion run {source} failed: {error}', [
            'source' => $run->getSource()->value,
            'error' => $run->getErrorSummary(),
        ]);
    }

    /**
     * Marks attempts still Running since before $before as failed, releasing the
     * window they hold under uniq_ingestion_run_active.
     *
     * Deliberately manual, not a cron: an orphan row is evidence of a crash, and a
     * job that quietly tidied it away every night would erase the only trace. It is
     * recorded as a failure rather than deleted, for the same reason.
     *
     * @return int rows abandoned
     */
    public function abandonStale(\DateTimeImmutable $before): int
    {
        $abandoned = (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE raw.ingestion_run
                   SET status = :failed, finished_at = :now, error_class = :class, error = :error
                 WHERE status = :running AND started_at < :before
                SQL,
            [
                'failed' => IngestionStatus::Failed->value,
                'now' => $this->clock->now(),
                'class' => self::ABANDONED,
                'error' => \sprintf('Still Running at %s, long after it should have finished.', $this->clock->now()->format('c')),
                'running' => IngestionStatus::Running->value,
                'before' => $before,
            ],
            [
                'now' => Types::DATETIMETZ_IMMUTABLE,
                'before' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        if ($abandoned > 0) {
            $this->logger->warning('Abandoned {count} ingestion run(s) left Running by a dead worker', ['count' => $abandoned]);
        }

        return $abandoned;
    }

    /**
     * Terminal states go out over DBAL rather than the EntityManager on purpose.
     * A handler that throws mid-flush leaves the EntityManager closed, and a closed
     * EntityManager cannot persist the very row that records why — the failure
     * would be the one event never written down.
     */
    private function write(IngestionRun $run): void
    {
        $this->connection->update(
            'raw.ingestion_run',
            [
                'status' => $run->getStatus()->value,
                'finished_at' => $run->getFinishedAt(),
                'rows_written' => $run->getRowsWritten(),
                'error_class' => $run->getErrorClass(),
                'error' => $run->getError(),
                'details' => $run->getDetails(),
            ],
            ['id' => $run->getId()],
            [
                'finished_at' => Types::DATETIMETZ_IMMUTABLE,
                'details' => Types::JSONB,
            ],
        );
    }
}
