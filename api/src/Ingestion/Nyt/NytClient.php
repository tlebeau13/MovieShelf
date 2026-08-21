<?php

declare(strict_types=1);

namespace App\Ingestion\Nyt;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads NYT's weekly bestseller lists (#6).
 *
 * The key and the retry policy live on the scoped client in framework.yaml; what
 * this class adds is the pacing, because NYT's limits are two and only one of them
 * is documented (see db/README.md): 1,000 requests/day, plus an undocumented burst
 * cap that answered `429` to probes spaced ~7 seconds apart.
 */
final class NytClient
{
    /** The endpoint's stand-in for a date, and the only one the weekly job uses. */
    private const CURRENT = 'current';

    private ?float $lastRequestAt = null;
    private int $requests = 0;

    public function __construct(
        private readonly HttpClientInterface $nytClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%nyt.min_request_interval%')]
        private readonly float $minInterval,
    ) {
    }

    /**
     * @param string                  $listName NYT's list slug, e.g. `hardcover-fiction`
     * @param \DateTimeImmutable|null $week     a past date, snapped by the API to the
     *                                          list actually published that week; null
     *                                          asks for the current list
     *
     * @throws NytResponseException when the body is not a list
     */
    public function fetchList(string $listName, ?\DateTimeImmutable $week = null): NytList
    {
        $path = \sprintf('lists/%s/%s.json', $week?->format('Y-m-d') ?? self::CURRENT, $listName);

        $this->pace();
        ++$this->requests;

        $payload = $this->nytClient->request('GET', $path)->toArray();

        $list = NytList::fromPayload($payload);

        $this->logger->info('Fetched NYT {list} for {week}: {books} books', [
            'list' => $listName,
            'week' => $list->publishedDate->format('Y-m-d'),
            'books' => \count($list->books),
        ]);

        return $list;
    }

    /**
     * Requests this instance has made, for the run record's `details`.
     *
     * Logical fetches, not HTTP attempts: the scoped client's retries are transparent
     * and are not counted here, so a run that shows 40 requests may have spent more
     * of the daily quota than that. The backfill budget is deliberately set below
     * the quota for that reason.
     */
    public function requestsMade(): int
    {
        return $this->requests;
    }

    /**
     * Sleeps out the rest of the minimum interval since the last request.
     *
     * Blocking on purpose: this runs in a worker or a CLI backfill, both of which
     * have nothing else to do, and the alternative — firing and handling the 429 —
     * spends quota to learn something already known.
     */
    private function pace(): void
    {
        if (null !== $this->lastRequestAt) {
            $wait = $this->minInterval - (microtime(true) - $this->lastRequestAt);

            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
