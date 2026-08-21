<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ingestion\Nyt\NytList;
use App\Ingestion\Nyt\NytResponseException;
use PHPUnit\Framework\TestCase;

/**
 * The mapping from NYT's response to what `raw.nyt_snapshot` keeps (#6).
 *
 * Runs on the recorded fixture and never touches the network: NYT's key is a
 * secret CI forks do not have, and a parser test that needs one is a parser test
 * nobody outside the repo can run (api/README.md).
 */
final class NytListTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/nyt/hardcover-fiction.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testItKeepsTheListsOwnPublishedDate(): void
    {
        $list = NytList::fromPayload(self::fixture());

        // published_date (the Sunday the list is dated), not bestsellers_date
        // (2026-08-01, the sales week it measures). Getting these two the wrong way
        // round would file every row under the wrong week and still look plausible.
        self::assertSame('2026-08-16', $list->publishedDate->format('Y-m-d'));
        self::assertSame('2026-08-09', $list->previousPublishedDate?->format('Y-m-d'));
        self::assertSame('hardcover-fiction', $list->listName);
    }

    public function testItReadsTheFieldsTheSnapshotStores(): void
    {
        $book = NytList::fromPayload(self::fixture())->books[1];

        self::assertSame(2, $book->rank);
        self::assertSame(1, $book->rankLastWeek);
        self::assertSame(13, $book->weeksOnList);
        self::assertSame('9781954118812', $book->isbn13);
        self::assertNotSame('', $book->title);
        self::assertNotSame('', $book->author);
    }

    public function testItKeepsRankLastWeekZeroAsTheSentinelItIs(): void
    {
        // 0 means "not on the list last week", which every new entry carries. Mapping
        // it to null here would make it indistinguishable from an ingestion gap, and
        // #12/#17 read this column to tell those apart.
        $newEntry = NytList::fromPayload(self::fixture())->books[0];

        self::assertSame(1, $newEntry->weeksOnList);
        self::assertSame(0, $newEntry->rankLastWeek);
    }

    public function testItRefusesABodyThatIsNotAList(): void
    {
        // What an error body or a changed shape looks like from here. Failing loudly
        // beats writing a week with zero books, which reads as "the list was empty".
        $this->expectException(NytResponseException::class);

        NytList::fromPayload(['fault' => ['faultstring' => 'Invalid ApiKey']]);
    }

    public function testItRefusesAListWithNoPublishedDate(): void
    {
        $payload = self::fixture();
        unset($payload['results']['published_date']);

        $this->expectException(NytResponseException::class);

        NytList::fromPayload($payload);
    }
}
