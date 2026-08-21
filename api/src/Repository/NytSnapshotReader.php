<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Read queries over `raw.nyt_snapshot`, for looking at what #6 crawled.
 *
 * DBAL and no entity, matching the writer: RAW is a landing zone, and hydrating
 * 1,275 objects to serialize them straight back to JSON would buy nothing.
 *
 * This is a window onto RAW, not the REST API. #16 serves MART — computed answers
 * — and these go away or move behind it when it lands.
 */
final readonly class NytSnapshotReader
{
    public const DEFAULT_LIST = 'hardcover-fiction';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function weeks(string $list): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT to_char(s.published_date, 'YYYY-MM-DD') AS published_date,
                       count(*)                                AS books,
                       max(r.started_at)                       AS ingested_at
                  FROM raw.nyt_snapshot s
                  JOIN raw.ingestion_run r ON r.id = s.run_id
                 WHERE s.list_name = :list
                 GROUP BY s.published_date
                 ORDER BY s.published_date DESC
                SQL,
            ['list' => $list],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function week(string $list, string $publishedDate): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT rank, rank_last_week, weeks_on_list, isbn13, title, author
                  FROM raw.nyt_snapshot
                 WHERE list_name = :list AND published_date = :week
                 ORDER BY rank
                SQL,
            ['list' => $list, 'week' => $publishedDate],
        );
    }

    /**
     * One book's whole trajectory, oldest week first.
     *
     * @return list<array<string, mixed>>
     */
    public function book(string $list, string $isbn13): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT to_char(published_date, 'YYYY-MM-DD') AS week,
                       rank, rank_last_week, weeks_on_list, title, author
                  FROM raw.nyt_snapshot
                 WHERE list_name = :list AND isbn13 = :isbn
                 ORDER BY published_date
                SQL,
            ['list' => $list, 'isbn' => $isbn13],
        );
    }

    /**
     * @return array{weeks: int, books: int, rows: int, first: ?string, last: ?string}
     */
    public function coverage(string $list): array
    {
        /** @var array{weeks: int, books: int, rows: int, first: ?string, last: ?string} $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT count(DISTINCT published_date)          AS weeks,
                       count(DISTINCT isbn13)                  AS books,
                       count(*)                                AS rows,
                       to_char(min(published_date), 'YYYY-MM-DD') AS first,
                       to_char(max(published_date), 'YYYY-MM-DD') AS last
                  FROM raw.nyt_snapshot
                 WHERE list_name = :list
                SQL,
            ['list' => $list],
        );

        return $row;
    }

    /**
     * Which Sundays between the first and last stored week have no rows.
     *
     * The gap, not the total, is what says whether a chart drawn off this data would
     * be lying: a missing week reads as a flat line rather than as absence.
     *
     * @return list<string>
     */
    public function missingWeeks(string $list, string $first, string $last): array
    {
        $stored = array_fill_keys($this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT to_char(published_date, 'YYYY-MM-DD')
                  FROM raw.nyt_snapshot
                 WHERE list_name = :list
                SQL,
            ['list' => $list],
        ), true);

        $missing = [];
        $week = new \DateTimeImmutable($first);
        $end = new \DateTimeImmutable($last);

        while ($week <= $end) {
            if (!isset($stored[$week->format('Y-m-d')])) {
                $missing[] = $week->format('Y-m-d');
            }

            $week = $week->modify('+7 days');
        }

        return $missing;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit = 5): array
    {
        $runs = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, status, started_at, finished_at, rows_written,
                       to_char(window_start, 'YYYY-MM-DD') AS window_start,
                       to_char(window_end, 'YYYY-MM-DD')   AS window_end,
                       error_class, details
                  FROM raw.ingestion_run
                 WHERE source = 'nyt'
                 ORDER BY id DESC
                 LIMIT :limit
                SQL,
            ['limit' => $limit],
        );

        // DBAL hands jsonb back as a string; decoding here keeps `details` an object
        // in the response instead of a string containing JSON.
        return array_map(
            static fn (array $run) => [...$run, 'details' => json_decode((string) $run['details'], true)],
            $runs,
        );
    }
}
