<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\IngestionRun;
use App\Enum\IngestionSource;
use App\Enum\IngestionStatus;
use App\Ingestion\IngestionAlreadyRunning;
use App\Ingestion\IngestionResult;
use App\Ingestion\IngestionRunRecorder;
use App\Repository\IngestionRunRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The shape #5/#6/#7 all write (#29), asserted rather than described.
 *
 * Runs against the live contract database inside a transaction that is always
 * rolled back, like WriteBoundaryTest — the point is that the row the `symfony`
 * role actually writes is the documented one.
 */
final class IngestionRunTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private IngestionRunRecorder $recorder;
    private IngestionRunRepository $runs;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->recorder = $container->get(IngestionRunRecorder::class);
        $this->runs = $container->get(IngestionRunRepository::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheTableLivesInTheRawSchema(): void
    {
        // A table that drifted into `public` would sidestep the grants db/ exists
        // to enforce, and analytics would silently lose read access.
        self::assertSame('raw', $this->connection->fetchOne(
            "SELECT table_schema FROM information_schema.tables WHERE table_name = 'ingestion_run'"
        ));
    }

    public function testASuccessfulRunIsRecorded(): void
    {
        $run = $this->recorder->record(
            IngestionSource::Nyt,
            static fn (IngestionRun $run) => new IngestionResult(42),
            new \DateTimeImmutable('2026-08-16'),
            new \DateTimeImmutable('2026-08-16'),
        );

        $row = $this->rowFor($run);

        self::assertSame(IngestionStatus::Success, $run->getStatus());
        self::assertSame('success', $row['status']);
        self::assertSame('nyt', $row['source']);
        self::assertSame(42, $row['rows_written']);
        self::assertNotNull($row['finished_at']);
        self::assertSame('2026-08-16', $row['window_start']);
        self::assertNull($row['error']);
    }

    public function testAFailingRunIsRecordedAndTheExceptionStillEscapes(): void
    {
        // Messenger's retry policy (#8) only fires if the handler still throws, so
        // recording must not turn a failure into a return value.
        try {
            $this->recorder->record(IngestionSource::Tmdb, static function (): IngestionResult {
                throw new \RuntimeException('TMDB said 503');
            });

            self::fail('The exception should have been re-thrown after recording.');
        } catch (\RuntimeException $e) {
            self::assertSame('TMDB said 503', $e->getMessage());
        }

        $row = $this->connection->fetchAssociative(
            "SELECT status, error_class, error, rows_written FROM raw.ingestion_run WHERE source = 'tmdb' ORDER BY id DESC LIMIT 1"
        );

        self::assertSame('failed', $row['status']);
        self::assertSame(0, $row['rows_written']);
        // Class apart from message: "how many 503s" has to be a GROUP BY, not a LIKE.
        self::assertSame(\RuntimeException::class, $row['error_class']);
        self::assertSame('TMDB said 503', $row['error']);
    }

    public function testTheApiKeyNeverReachesTheRow(): void
    {
        // HttpClient puts the whole URL in its exception message, and the ingestion
        // key rides in the query string — so the failure most worth recording is the
        // one carrying the secret. Observed for real on #6's backfill: a 400 from NYT
        // stored the key in this column, where `analytics` can read it and
        // `app:ingestion:runs` prints it.
        $leak = new \RuntimeException(
            'HTTP/2 400 returned for "https://api.nytimes.com/svc/books/v3/lists/2025-09-21/hardcover-fiction.json?api-key=s3cr3tvalue".'
        );

        $run = $this->recorder->start(IngestionSource::Nyt);
        $this->recorder->fail($run, $leak);

        $stored = (string) $this->rowFor($run)['error'];

        self::assertStringNotContainsString('s3cr3tvalue', $stored);
        self::assertStringContainsString('api-key=[redacted]', $stored);
        // Redacting must not cost the part that identifies the failure.
        self::assertStringContainsString('400', $stored);
        self::assertStringContainsString('2025-09-21', $stored);
    }

    public function testTheRunningRowIsWrittenBeforeTheWorkStarts(): void
    {
        // What makes a killed worker visible afterwards: without this, a crash
        // leaves no row at all and the run looks like it never happened.
        $run = $this->recorder->start(IngestionSource::OpenLibrary);

        self::assertNotNull($run->getId());
        self::assertSame('running', $this->rowFor($run)['status']);
        self::assertNull($run->getFinishedAt());
    }

    public function testDetailsSurviveTheRoundTrip(): void
    {
        $run = $this->recorder->start(IngestionSource::Nyt);
        $this->recorder->succeed($run, new IngestionResult(7, ['requests' => 12, 'throttled' => 1]));

        $this->entityManager->clear();
        $reloaded = $this->runs->find($run->getId());

        self::assertSame(['requests' => 12, 'throttled' => 1], $reloaded->getDetails());
        self::assertSame(7, $reloaded->getRowsWritten());
    }

    public function testFindLatestPerSourceReturnsTheMostRecentAttempt(): void
    {
        // Both attempts land in the same second, and started_at is TIMESTAMP(0):
        // this passes only because DISTINCT ON tie-breaks on id. A retry is exactly
        // this case.
        $this->recorder->record(IngestionSource::Nyt, static fn () => new IngestionResult(1));
        $second = $this->recorder->record(IngestionSource::Nyt, static fn () => new IngestionResult(2));

        $this->entityManager->clear();
        $latest = $this->runs->findLatestPerSource();

        self::assertSame($second->getId(), $latest['nyt']->getId());

        // A source that never ran is absent rather than a fabricated empty row.
        self::assertArrayNotHasKey('tmdb', $latest);
    }

    public function testTwoAttemptsAtTheSameWindowCannotBeInFlightAtOnce(): void
    {
        // A queue redelivers: a worker that dies after handling but before acking
        // gets the same message again. Without uniq_ingestion_run_active both copies
        // would crawl the same window and write it twice.
        $week = new \DateTimeImmutable('2026-08-16');
        $this->recorder->start(IngestionSource::Nyt, $week, $week);

        $this->expectException(IngestionAlreadyRunning::class);
        $this->recorder->start(IngestionSource::Nyt, $week, $week);
    }

    public function testAFinishedAttemptReleasesItsWindow(): void
    {
        // The constraint is partial on `running` on purpose: a retry is a new row,
        // so finished attempts must be free to pile up on the same window.
        $week = new \DateTimeImmutable('2026-08-16');

        $first = $this->recorder->start(IngestionSource::Nyt, $week, $week);
        $this->recorder->fail($first, new \RuntimeException('nope'));

        $second = $this->recorder->start(IngestionSource::Nyt, $week, $week);

        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testAbandoningStaleRunsReleasesTheWindowAndKeepsTheEvidence(): void
    {
        $week = new \DateTimeImmutable('2026-08-16');
        $orphan = $this->recorder->start(IngestionSource::Nyt, $week, $week);

        // Backdate it rather than sleeping: what makes a run stale is its age.
        $this->connection->executeStatement(
            "UPDATE raw.ingestion_run SET started_at = NOW() - interval '30 hours' WHERE id = ?",
            [$orphan->getId()],
        );

        self::assertSame(1, $this->recorder->abandonStale(new \DateTimeImmutable('-24 hours')));

        $row = $this->rowFor($orphan);
        self::assertSame('failed', $row['status']);
        self::assertSame(IngestionRunRecorder::ABANDONED, $row['error_class']);
        self::assertNotNull($row['finished_at']);

        // The window is free again, which is the whole point of abandoning it.
        $this->recorder->start(IngestionSource::Nyt, $week, $week);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(IngestionRun $run): array
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM raw.ingestion_run WHERE id = ?',
            [$run->getId()],
        );
    }
}
