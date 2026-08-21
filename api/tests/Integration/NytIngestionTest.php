<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\NytBackfillCommand;
use App\Ingestion\IngestionRunRecorder;
use App\Ingestion\Nyt\NytClient;
use App\Ingestion\Nyt\NytSnapshotWriter;
use App\Message\IngestNytWeek;
use App\MessageHandler\IngestNytWeekHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NYT ingestion end to end, minus NYT (#6).
 *
 * Every response is the recorded fixture replayed through MockHttpClient — no test
 * in this suite calls the provider. The database, however, is the real one: the
 * claims worth asserting here (an upsert that collapses a re-crawled week, a table
 * the `symfony` role may write) only exist in Postgres.
 *
 * Wrapped in a transaction that is always rolled back, like IngestionRunTest.
 */
final class NytIngestionTest extends KernelTestCase
{
    /**
     * Deliberately not a real NYT slug. The suite runs against the contract database,
     * which by now holds actual backfilled weeks of `hardcover-fiction`, so every
     * assertion here is scoped to a list that only the fixtures ever write. Counting
     * rows table-wide would make these tests pass or fail on what someone crawled
     * last week.
     */
    private const LIST = 'phpunit-fixture-list';

    private Connection $connection;
    private int $runBaseline = 0;
    private NytSnapshotWriter $writer;
    private IngestionRunRecorder $recorder;
    private ClockInterface $clock;

    /** @var list<string> the date segment of each request, in order */
    private array $requested = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->writer = $container->get(NytSnapshotWriter::class);
        $this->recorder = $container->get(IngestionRunRecorder::class);
        $this->clock = $container->get(ClockInterface::class);

        $this->requested = [];
        // Same reason as the list name: ingestion_run holds real crawls, so "how many
        // runs did this test record" has to start counting from here.
        $this->runBaseline = (int) $this->connection->fetchOne('SELECT coalesce(max(id), 0) FROM raw.ingestion_run');

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
        // A table that drifted into `public` would sidestep the grants db/ exists to
        // enforce, and analytics would silently lose read access to it.
        self::assertSame('raw', $this->connection->fetchOne(
            "SELECT table_schema FROM information_schema.tables WHERE table_name = 'nyt_snapshot'"
        ));
    }

    public function testAWeekLandsWithTheFieldsAnalyticsReads(): void
    {
        $this->handler()(new IngestNytWeek(self::LIST, '2026-08-16'));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM raw.nyt_snapshot WHERE list_name = :list AND published_date = :week ORDER BY rank',
            ['list' => self::LIST, 'week' => '2026-08-16'],
        );

        self::assertCount(2, $rows);
        self::assertSame(self::LIST, $rows[0]['list_name']);
        self::assertSame('9798347122363', $rows[0]['isbn13']);
        self::assertSame('DAGGERMOUTH', $rows[0]['title']);
        self::assertSame(1, $rows[0]['weeks_on_list']);
        // The sentinel, stored as NYT gives it: 0 is "not on the list last week".
        self::assertSame(0, $rows[0]['rank_last_week']);
        self::assertNotNull($rows[0]['run_id']);
    }

    public function testRerunningTheSameWeekDoesNotDuplicateRows(): void
    {
        $handler = $this->handler();

        $handler(new IngestNytWeek(self::LIST, '2026-08-16'));
        $handler(new IngestNytWeek(self::LIST, '2026-08-16'));

        // The acceptance criterion on #6: the second crawl of a week updates its rows
        // rather than adding a second copy of them.
        self::assertSame(2, $this->rowCount('2026-08-16'));
        self::assertSame(2, \count($this->requested));

        // Two runs recorded, though — a row is an attempt, not a logical week (#29).
        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM raw.ingestion_run WHERE source = 'nyt' AND status = 'success' AND id > :since",
            ['since' => $this->runBaseline],
        ));
    }

    public function testAReCrawlUpdatesTheRowInPlace(): void
    {
        $this->handler()(new IngestNytWeek(self::LIST, '2026-08-16'));

        // The same week fetched again after NYT revised it: same list, same week, same
        // ISBN, different rank. Idempotency has to mean "one row, current values",
        // not "the first version wins".
        $revised = $this->fixture();
        $revised['results']['list_name_encoded'] = self::LIST;
        $revised['results']['books'][0]['rank'] = 4;
        $revised['results']['books'][0]['weeks_on_list'] = 2;

        $this->handler(static fn () => new MockResponse(json_encode($revised, \JSON_THROW_ON_ERROR)))(
            new IngestNytWeek(self::LIST, '2026-08-16')
        );

        $row = $this->connection->fetchAssociative(
            'SELECT rank, weeks_on_list FROM raw.nyt_snapshot WHERE list_name = :list AND isbn13 = :isbn AND published_date = :week',
            ['list' => self::LIST, 'isbn' => '9798347122363', 'week' => '2026-08-16'],
        );

        self::assertSame(2, $this->rowCount('2026-08-16'));
        self::assertSame(4, $row['rank']);
        self::assertSame(2, $row['weeks_on_list']);
    }

    public function testAnEntryWithoutAnIsbnIsSkippedRatherThanStored(): void
    {
        // isbn13 is half the unique key and the join to Open Library (#7). Storing a
        // row without one under a placeholder would collide with the next such row.
        $payload = $this->fixture();
        $payload['results']['list_name_encoded'] = self::LIST;
        $payload['results']['books'][0]['primary_isbn13'] = '';

        $this->handler(static fn () => new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR)))(
            new IngestNytWeek(self::LIST, '2026-08-16')
        );

        self::assertSame(1, $this->rowCount('2026-08-16'));
    }

    public function testTheRunRecordsWhatTheWeekCost(): void
    {
        $this->handler()(new IngestNytWeek(self::LIST, '2026-08-16'));

        $run = $this->connection->fetchAssociative(
            "SELECT * FROM raw.ingestion_run WHERE source = 'nyt' AND id > :since ORDER BY id DESC LIMIT 1", ['since' => $this->runBaseline]
        );
        $details = json_decode((string) $run['details'], true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('success', $run['status']);
        self::assertSame(2, $run['rows_written']);
        self::assertSame('2026-08-16', $run['window_start']);
        self::assertSame('2026-08-16', $details['published_date']);
        self::assertSame(1, $details['requests']);
    }

    public function testACurrentFetchFilesRowsUnderTheWeekTheResponseNames(): void
    {
        // NYT publishes a list days before the Sunday it is dated, so `current` is the
        // one case where the week is only known from the response. The rows still have
        // to land under it, not under the fetch date.
        $this->handler()(new IngestNytWeek(self::LIST));

        self::assertSame(['current'], $this->requested);
        self::assertSame(2, $this->rowCount('2026-08-16'));

        $run = $this->connection->fetchAssociative(
            "SELECT window_start, details FROM raw.ingestion_run WHERE source = 'nyt' AND id > :since ORDER BY id DESC LIMIT 1", ['since' => $this->runBaseline]
        );

        self::assertNull($run['window_start']);
        self::assertSame('2026-08-16', json_decode((string) $run['details'], true, flags: \JSON_THROW_ON_ERROR)['published_date']);
    }

    public function testTheBackfillStopsAtItsBudgetAndResumesWhereItStopped(): void
    {
        $tester = new CommandTester($this->backfillCommand());

        // Five Sundays in range, only two requests allowed.
        $tester->execute(['--list' => self::LIST, '--from' => '2026-07-05', '--to' => '2026-08-02', '--budget' => 2]);

        self::assertSame(['2026-07-05', '2026-07-12'], $this->requested);
        self::assertSame(2, $this->weeksStored());

        $run = $this->connection->fetchAssociative(
            "SELECT window_start, window_end, rows_written, details FROM raw.ingestion_run WHERE source = 'nyt' AND id > :since ORDER BY id DESC LIMIT 1", ['since' => $this->runBaseline]
        );
        $details = json_decode((string) $run['details'], true, flags: \JSON_THROW_ON_ERROR);

        // One run for the whole invocation, windowed on the range rather than a week,
        // so a backfill shows up in `app:ingestion:runs` as one line.
        self::assertSame('2026-07-05', $run['window_start']);
        self::assertSame('2026-08-02', $run['window_end']);
        self::assertSame(4, $run['rows_written']);
        self::assertSame('2026-07-19', $details['next_week']);

        // Resuming is re-running the same command: the stored weeks are the progress
        // record, so the two already fetched are not fetched again.
        $this->requested = [];
        $tester->execute(['--list' => self::LIST, '--from' => '2026-07-05', '--to' => '2026-08-02', '--budget' => 10]);

        self::assertSame(['2026-07-19', '2026-07-26', '2026-08-02'], $this->requested);
        self::assertSame(5, $this->weeksStored());

        // And a third run has nothing left to do, without spending a request to learn it.
        $this->requested = [];
        $tester->execute(['--list' => self::LIST, '--from' => '2026-07-05', '--to' => '2026-08-02', '--budget' => 10]);

        self::assertSame([], $this->requested);
        self::assertStringContainsString('Nothing to backfill', $tester->getDisplay());
    }

    public function testTheBackfillReportsWhatItWouldSpendWithoutSpendingIt(): void
    {
        $tester = new CommandTester($this->backfillCommand());
        $tester->execute(['--list' => self::LIST, '--from' => '2026-07-05', '--to' => '2026-07-19', '--dry-run' => true]);

        self::assertSame([], $this->requested);
        self::assertSame(0, $this->weeksStored());
        self::assertStringContainsString('3 to fetch', $tester->getDisplay());
    }

    public function testTheBackfillRecordsThePartOfItThatLanded(): void
    {
        $failAfterTwo = function (string $method, string $url): MockResponse {
            if (\count($this->requested) > 2) {
                return new MockResponse('{"fault":{"faultstring":"Quota exceeded"}}', ['http_code' => 429]);
            }

            return $this->replay($url);
        };

        $tester = new CommandTester($this->backfillCommand($failAfterTwo));
        $exit = $tester->execute(['--list' => self::LIST, '--from' => '2026-07-05', '--to' => '2026-08-02', '--budget' => 10]);

        $run = $this->connection->fetchAssociative(
            "SELECT status, rows_written, details FROM raw.ingestion_run WHERE source = 'nyt' AND id > :since ORDER BY id DESC LIMIT 1", ['since' => $this->runBaseline]
        );

        // The weeks that landed before the failure are real rows and stay written; the
        // run is Failed all the same, and says how far it got.
        self::assertSame(1, $exit);
        self::assertSame('failed', $run['status']);
        self::assertSame(2, $this->weeksStored());
        self::assertSame(4, $run['rows_written']);
        self::assertSame('2026-07-19', json_decode((string) $run['details'], true, flags: \JSON_THROW_ON_ERROR)['next_week']);
    }

    private function handler(?callable $responses = null): IngestNytWeekHandler
    {
        return new IngestNytWeekHandler($this->client($responses), $this->writer, $this->recorder);
    }

    private function backfillCommand(?callable $responses = null): NytBackfillCommand
    {
        return new NytBackfillCommand($this->client($responses), $this->writer, $this->recorder, $this->clock);
    }

    /**
     * The fixture, served for whatever week is asked for. The pacing is zeroed by
     * `when@test` in services.yaml, so the suite does not sit out a 12-second wait
     * between requests.
     */
    private function client(?callable $responses = null): NytClient
    {
        $record = function (string $method, string $url) use ($responses): MockResponse {
            preg_match('#/lists/([^/]+)/#', $url, $matches);
            $this->requested[] = $matches[1];

            return null !== $responses ? $responses($method, $url) : $this->replay($url);
        };

        return new NytClient(new MockHttpClient($record, 'https://api.nytimes.com/svc/books/v3/'), new NullLogger(), 0.0);
    }

    /** The recorded response, re-dated and re-slugged to what the caller asked for. */
    private function replay(string $url): MockResponse
    {
        preg_match('#/lists/([^/]+)/([^/]+)\.json#', $url, $matches);
        $payload = $this->fixture();

        if ('current' !== $matches[1]) {
            $payload['results']['published_date'] = $matches[1];
        }

        // NYT echoes back the slug it was asked for, which is what keeps these rows
        // under the fixture-only list rather than on top of real `hardcover-fiction`.
        $payload['results']['list_name_encoded'] = $matches[2];

        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/nyt/hardcover-fiction.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function rowCount(string $week): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM raw.nyt_snapshot WHERE list_name = :list AND published_date = :week',
            ['list' => self::LIST, 'week' => $week],
        );
    }

    private function weeksStored(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(DISTINCT published_date) FROM raw.nyt_snapshot WHERE list_name = :list',
            ['list' => self::LIST],
        );
    }
}
