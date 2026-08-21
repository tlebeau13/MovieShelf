<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\IngestionSource;
use App\Ingestion\IngestionResult;
use App\Ingestion\IngestionRunRecorder;
use App\Ingestion\Nyt\NytList;
use App\Ingestion\Nyt\NytSnapshotWriter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The dev-only window onto `raw.nyt_snapshot` (#6).
 *
 * Scoped to a fixture-only list for the same reason as NytIngestionTest: the
 * contract database holds real crawled weeks, and an assertion counting rows
 * table-wide would depend on them.
 */
final class NytApiTest extends WebTestCase
{
    private const LIST = 'phpunit-api-list';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();

        // Two weeks a fortnight apart, so the week between them is a real gap.
        $this->store('2026-07-05');
        $this->store('2026-07-19');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItListsTheWeeksNewestFirst(): void
    {
        $body = $this->get('/api/nyt/weeks');

        self::assertSame(self::LIST, $body['list']);
        self::assertCount(2, $body['weeks']);
        self::assertSame('2026-07-19', $body['weeks'][0]['published_date']);
        self::assertSame(2, $body['weeks'][0]['books']);
        self::assertNotNull($body['weeks'][0]['ingested_at']);
    }

    public function testItServesOneWeekInRankOrder(): void
    {
        $body = $this->get('/api/nyt/weeks/2026-07-05');

        self::assertSame([1, 2], array_column($body['books'], 'rank'));
        self::assertSame('DAGGERMOUTH', $body['books'][0]['title']);
        // The sentinel is passed through untouched, with the reading of it alongside
        // so a client does not have to know that 0 means absence.
        self::assertSame(0, $body['books'][0]['rank_last_week']);
        self::assertTrue($body['books'][0]['entered_this_week']);
        self::assertFalse($body['books'][1]['entered_this_week']);
    }

    public function testAWeekThatWasNeverCrawledIsNotAnEmptyList(): void
    {
        // 404, not 200 with []: the second would read as "NYT published nothing that
        // week", which is a different claim from "we never asked".
        $this->client->request('GET', '/api/nyt/weeks/2026-07-12?list='.self::LIST);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testItServesABooksTrajectory(): void
    {
        $body = $this->get('/api/nyt/books/9798347122363');

        self::assertSame('DAGGERMOUTH', $body['title']);
        self::assertSame(2, $body['weeks_charted']);
        self::assertSame(1, $body['best_rank']);
        self::assertSame(['2026-07-05', '2026-07-19'], array_column($body['series'], 'week'));
    }

    public function testCoverageNamesTheGapsInsideTheRange(): void
    {
        $body = $this->get('/api/nyt/coverage');

        self::assertSame(2, $body['weeks']);
        self::assertSame(4, $body['rows']);
        self::assertSame('2026-07-05', $body['first']);
        self::assertSame('2026-07-19', $body['last']);
        // The number that decides whether a chart drawn off this data is honest: a
        // week missing in the middle reads as a flat line, not as absence.
        self::assertSame(['2026-07-12'], $body['missing_weeks']);
        self::assertNotEmpty($body['runs']);
        // details is an object, not a string containing JSON.
        self::assertIsArray($body['runs'][0]['details']);
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $this->client->request('GET', $path.'?list='.self::LIST);

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function store(string $week): void
    {
        $container = self::getContainer();
        $payload = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/nyt/hardcover-fiction.json'), true, flags: \JSON_THROW_ON_ERROR);
        $payload['results']['list_name_encoded'] = self::LIST;
        $payload['results']['published_date'] = $week;

        $recorder = $container->get(IngestionRunRecorder::class);
        $writer = $container->get(NytSnapshotWriter::class);

        $recorder->record(
            IngestionSource::Nyt,
            static fn ($run) => new IngestionResult($writer->write($run, NytList::fromPayload($payload))),
            new \DateTimeImmutable($week),
            new \DateTimeImmutable($week),
        );
    }
}
