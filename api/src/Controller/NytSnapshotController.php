<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NytSnapshotReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A read-only window onto what #6 crawled, so the data can be eyeballed without
 * opening psql.
 *
 * **Dev only, on purpose**, enforced in the constructor below. Two reasons: nothing
 * in this app authenticates yet, and these serve RAW — NYT's own list content,
 * unaggregated. The public REST API is #16, it serves MART, and it is a different
 * shape by design.
 */
#[Route('/api/nyt')]
final class NytSnapshotController extends AbstractController
{
    public function __construct(
        private readonly NytSnapshotReader $reader,
        #[Autowire('%kernel.debug%')]
        bool $debug = false,
    ) {
        // In the constructor rather than per action, and a 404 rather than a 403: in
        // prod these routes should look like they were never written. A route
        // `condition` would express this declaratively but needs
        // symfony/expression-language, which is a dependency for one line.
        if (!$debug) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/weeks', name: 'nyt_weeks', methods: ['GET'])]
    public function weeks(Request $request): JsonResponse
    {
        $list = $this->list($request);

        return $this->json([
            'list' => $list,
            'weeks' => $this->reader->weeks($list),
        ]);
    }

    #[Route('/weeks/{publishedDate}', name: 'nyt_week', requirements: ['publishedDate' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function week(Request $request, string $publishedDate): JsonResponse
    {
        $list = $this->list($request);
        $books = $this->reader->week($list, $publishedDate);

        if ([] === $books) {
            // A week with no rows is not an empty list, it is a week never crawled —
            // `/api/nyt/coverage` says which those are.
            return $this->json([
                'error' => \sprintf('No rows for %s on %s. Not crawled, or not a Sunday.', $list, $publishedDate),
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'list' => $list,
            'published_date' => $publishedDate,
            'books' => array_map($this->annotate(...), $books),
        ]);
    }

    #[Route('/books/{isbn13}', name: 'nyt_book', requirements: ['isbn13' => Requirement::DIGITS], methods: ['GET'])]
    public function book(Request $request, string $isbn13): JsonResponse
    {
        $list = $this->list($request);
        $series = $this->reader->book($list, $isbn13);

        if ([] === $series) {
            return $this->json(['error' => \sprintf('No rows for ISBN %s on %s.', $isbn13, $list)], Response::HTTP_NOT_FOUND);
        }

        $latest = $series[array_key_last($series)];

        return $this->json([
            'list' => $list,
            'isbn13' => $isbn13,
            'title' => $latest['title'],
            'author' => $latest['author'],
            'weeks_charted' => \count($series),
            'best_rank' => min(array_column($series, 'rank')),
            'series' => array_map(
                fn (array $row) => [
                    'week' => $row['week'],
                    'rank' => $row['rank'],
                    'weeks_on_list' => $row['weeks_on_list'],
                    'entered_this_week' => 0 === $row['rank_last_week'],
                ],
                $series,
            ),
        ]);
    }

    #[Route('/coverage', name: 'nyt_coverage', methods: ['GET'])]
    public function coverage(Request $request): JsonResponse
    {
        $list = $this->list($request);
        $coverage = $this->reader->coverage($list);

        $missing = null !== $coverage['first'] && null !== $coverage['last']
            ? $this->reader->missingWeeks($list, $coverage['first'], $coverage['last'])
            : [];

        return $this->json([
            'list' => $list,
            ...$coverage,
            // Gaps inside the stored range, which is the number that decides whether
            // a chart off this data is honest. Weeks outside the range are simply
            // not backfilled yet and are not counted here.
            'missing_weeks' => $missing,
            'runs' => $this->reader->recentRuns(),
        ]);
    }

    /**
     * `rank_last_week` is passed through untouched — 0 is NYT's sentinel for "was not
     * on the list last week", and #12/#17 need it — with the reading of it alongside,
     * so a client eyeballing the JSON does not have to know that.
     *
     * @param array<string, mixed> $book
     *
     * @return array<string, mixed>
     */
    private function annotate(array $book): array
    {
        return [...$book, 'entered_this_week' => 0 === $book['rank_last_week']];
    }

    private function list(Request $request): string
    {
        return $request->query->getString('list') ?: NytSnapshotReader::DEFAULT_LIST;
    }
}
