<?php

declare(strict_types=1);

namespace App\Ingestion\Nyt;

/**
 * One book's entry on one weekly list (#6).
 *
 * Only the fields `raw.nyt_snapshot` keeps: the response carries ~30 per book,
 * most of them merchandising (Amazon links, cover image sizes) and `price`, which
 * NYT stopped publishing and now returns as "0.00" for everything.
 */
final readonly class NytBook
{
    /**
     * @param int $rankLastWeek 0 means "was not on the list last week", NYT's own
     *                          sentinel, not a missing value — every new entry
     *                          carries it. Stored as given: #12/#17 need to tell
     *                          absence from a rank, and a null here would be
     *                          indistinguishable from an ingestion gap.
     */
    public function __construct(
        public int $rank,
        public int $rankLastWeek,
        public string $isbn13,
        public string $title,
        public string $author,
        public int $weeksOnList,
    ) {
    }

    /**
     * @param array<string, mixed> $book one entry of `results.books`
     */
    public static function fromPayload(array $book): self
    {
        return new self(
            rank: (int) ($book['rank'] ?? 0),
            rankLastWeek: (int) ($book['rank_last_week'] ?? 0),
            // primary_isbn13, never primary_isbn10: the 10 is sometimes empty, and
            // the 13 is what Open Library is joined on in #7.
            isbn13: trim((string) ($book['primary_isbn13'] ?? '')),
            title: trim((string) ($book['title'] ?? '')),
            author: trim((string) ($book['author'] ?? '')),
            weeksOnList: (int) ($book['weeks_on_list'] ?? 0),
        );
    }
}
