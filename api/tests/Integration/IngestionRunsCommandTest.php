<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\IngestionRunsCommand;
use App\Enum\IngestionSource;
use App\Ingestion\IngestionResult;
use App\Ingestion\IngestionRunRecorder;
use App\Repository\IngestionRunRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The staleness verdict (#29), on a clock the test owns.
 *
 * "Still running 30 hours later" is the one part of the record that depends on the
 * passage of time, so it is the one part that cannot be checked by seeding rows and
 * eyeballing them — which is exactly how it was checked before this file existed.
 */
final class IngestionRunsCommandTest extends KernelTestCase
{
    private const STALE_AFTER_HOURS = 24;

    private Connection $connection;
    private MockClock $clock;
    private IngestionRunRecorder $recorder;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();

        $this->clock = new MockClock('2026-08-20 03:00:00');
        $this->recorder = new IngestionRunRecorder(
            $container->get(EntityManagerInterface::class),
            $this->connection,
            $this->clock,
            new NullLogger(),
        );

        $this->command = new CommandTester(new IngestionRunsCommand(
            $container->get(IngestionRunRepository::class),
            $this->recorder,
            $this->clock,
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testARunStillGoingAfterItsCadenceIsReportedAsADeadWorker(): void
    {
        $this->recorder->start(IngestionSource::OpenLibrary);
        $this->clock->sleep(30 * 3600);

        self::assertSame(Command::FAILURE, $this->execute());
        self::assertStringContainsString('stale', $this->command->getDisplay());
        // Not the whole sentence: SymfonyStyle wraps the error block at 80 columns.
        self::assertStringContainsString('has been running since', $this->command->getDisplay());
    }

    public function testARunStillGoingWithinItsCadenceIsJustRunning(): void
    {
        // The same row, six hours in: a slow run is not a failed one.
        $this->recorder->start(IngestionSource::OpenLibrary);
        $this->clock->sleep(6 * 3600);

        self::assertSame(Command::SUCCESS, $this->execute());
        self::assertStringContainsString('running', $this->command->getDisplay());
        self::assertStringNotContainsString('stale', $this->command->getDisplay());
    }

    public function testAnOldFailureThatALaterRunFixedIsNotAnAlert(): void
    {
        $week = new \DateTimeImmutable('2026-08-16');

        $failed = $this->recorder->start(IngestionSource::Nyt, $week, $week);
        $this->recorder->fail($failed, new \RuntimeException('NYT said 429'));

        $this->clock->sleep(60);
        $this->recorder->record(IngestionSource::Nyt, static fn () => new IngestionResult(1500), $week, $week);

        // The listing still shows the failure; the verdict is about the last attempt.
        self::assertSame(Command::SUCCESS, $this->execute(['--source' => 'nyt']));
        self::assertStringContainsString('RuntimeException: NYT said 429', $this->command->getDisplay());
    }

    public function testAbandonStaleReleasesTheWindowAndSaysSo(): void
    {
        $this->recorder->start(IngestionSource::OpenLibrary);
        $this->clock->sleep(30 * 3600);

        $this->execute(['--abandon-stale' => true]);

        self::assertStringContainsString('Abandoned 1 stale run(s).', $this->command->getDisplay());
        self::assertSame(IngestionRunRecorder::ABANDONED, $this->connection->fetchOne(
            "SELECT error_class FROM raw.ingestion_run WHERE source = 'openlibrary' ORDER BY id DESC LIMIT 1"
        ));

        // Released: the source can start again without tripping the unique index.
        $this->recorder->start(IngestionSource::OpenLibrary);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input = []): int
    {
        return $this->command->execute($input + ['--stale-hours' => (string) self::STALE_AFTER_HOURS]);
    }
}
