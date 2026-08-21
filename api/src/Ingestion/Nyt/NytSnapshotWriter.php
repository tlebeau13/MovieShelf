<?php

declare(strict_types=1);

namespace App\Ingestion\Nyt;

use App\Entity\IngestionRun;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Writes one weekly list into `raw.nyt_snapshot` (#6).
 *
 * DBAL rather than the ORM: RAW is a landing zone, not a domain model — there is no
 * behaviour to hang off a snapshot row, and one statement per list beats fifteen
 * entity flushes.
 */
final readonly class NytSnapshotWriter
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Upserts every book on the list, keyed on the list week rather than on when we
     * fetched it, so re-crawling a week updates its rows instead of duplicating them
     * (uniq_nyt_snapshot_entry).
     *
     * @return int rows written
     */
    public function write(IngestionRun $run, NytList $list): int
    {
        $rows = [];

        foreach ($list->books as $book) {
            if ('' === $book->isbn13) {
                // The join key to Open Library (#7) and half of this table's unique
                // key. Observed present on every row; a row without one is dropped
                // rather than stored under a placeholder that would collide with the
                // next such row.
                $this->logger->warning('Skipped NYT entry with no ISBN-13: {title} (rank {rank}, {week})', [
                    'title' => $book->title,
                    'rank' => $book->rank,
                    'week' => $list->publishedDate->format('Y-m-d'),
                ]);

                continue;
            }

            $rows[] = $book;
        }

        if ([] === $rows) {
            return 0;
        }

        $values = [];
        $params = [];
        $types = [];

        foreach ($rows as $i => $book) {
            $values[] = \sprintf('(:run_%1$d, :list_%1$d, :rank_%1$d, :last_%1$d, :isbn_%1$d, :title_%1$d, :author_%1$d, :weeks_%1$d, :published_%1$d)', $i);

            $params += [
                'run_'.$i => $run->getId(),
                'list_'.$i => $list->listName,
                'rank_'.$i => $book->rank,
                'last_'.$i => $book->rankLastWeek,
                'isbn_'.$i => $book->isbn13,
                'title_'.$i => $book->title,
                'author_'.$i => $book->author,
                'weeks_'.$i => $book->weeksOnList,
                'published_'.$i => $list->publishedDate,
            ];

            $types += ['published_'.$i => Types::DATE_IMMUTABLE];
        }

        $sql = \sprintf(
            <<<'SQL'
                INSERT INTO raw.nyt_snapshot
                    (run_id, list_name, rank, rank_last_week, isbn13, title, author, weeks_on_list, published_date)
                VALUES %s
                ON CONFLICT (list_name, published_date, isbn13) DO UPDATE SET
                    run_id         = EXCLUDED.run_id,
                    rank           = EXCLUDED.rank,
                    rank_last_week = EXCLUDED.rank_last_week,
                    title          = EXCLUDED.title,
                    author         = EXCLUDED.author,
                    weeks_on_list  = EXCLUDED.weeks_on_list
                SQL,
            implode(', ', $values),
        );

        return (int) $this->connection->executeStatement($sql, $params, $types);
    }

    /**
     * Which of these weeks are already stored, so the backfill can skip them.
     *
     * The table is the resume state: a separate cursor would be a second source of
     * truth, and the first crash between the two would make them disagree.
     *
     * @param list<\DateTimeImmutable> $weeks
     *
     * @return array<string, true> keyed by Y-m-d
     */
    public function storedWeeks(string $listName, array $weeks): array
    {
        if ([] === $weeks) {
            return [];
        }

        $stored = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT to_char(published_date, 'YYYY-MM-DD')
                  FROM raw.nyt_snapshot
                 WHERE list_name = :list AND published_date IN (:weeks)
                SQL,
            [
                'list' => $listName,
                'weeks' => array_map(static fn (\DateTimeImmutable $w) => $w->format('Y-m-d'), $weeks),
            ],
            [
                'list' => ParameterType::STRING,
                'weeks' => ArrayParameterType::STRING,
            ],
        );

        return array_fill_keys($stored, true);
    }
}
