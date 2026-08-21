<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\IngestionRun;
use App\Enum\IngestionSource;
use App\Ingestion\IngestionAlreadyRunning;
use App\Ingestion\IngestionResult;
use App\Ingestion\IngestionRunRecorder;
use App\Ingestion\Nyt\NytClient;
use App\Ingestion\Nyt\NytSnapshotWriter;
use App\Message\IngestNytWeek;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The weekly NYT pull (#6): one list, one week, recorded as one ingestion run.
 */
#[AsMessageHandler]
final readonly class IngestNytWeekHandler
{
    public function __construct(
        private NytClient $client,
        private NytSnapshotWriter $writer,
        private IngestionRunRecorder $runs,
    ) {
    }

    public function __invoke(IngestNytWeek $message): void
    {
        $week = $message->week();

        try {
            $this->runs->record(
                IngestionSource::Nyt,
                function (IngestionRun $run) use ($message, $week): IngestionResult {
                    // A delta, not the client's total: the client is a shared service
                    // and a worker consumes many messages before it recycles.
                    $before = $this->client->requestsMade();

                    $list = $this->client->fetchList($message->listName, $week);
                    $rows = $this->writer->write($run, $list);

                    return new IngestionResult($rows, [
                        'list' => $list->listName,
                        // The week the rows landed under, which for a `current` fetch
                        // is only known now: NYT publishes a list days before the
                        // Sunday it is dated, so the fetch date does not imply it.
                        'published_date' => $list->publishedDate->format('Y-m-d'),
                        'requests' => $this->client->requestsMade() - $before,
                    ]);
                },
                // Null for a `current` fetch, and deliberately so: the window has to
                // be written before the request that would reveal it. The upsert is
                // what keeps a redelivered `current` message harmless, so the run
                // record gives up the guarantee that uniq_ingestion_run_active would
                // add rather than inventing a week it cannot know yet.
                windowStart: $week,
                windowEnd: $week,
            );
        } catch (IngestionAlreadyRunning $e) {
            // Retrying cannot help — the conflicting run is still there — so this
            // goes straight to the failure transport instead of burning the retries.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }
}
