<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Enum\IngestionSource;

/**
 * Another attempt at this source and window is already in flight (#29).
 *
 * Thrown instead of the raw unique-violation so a handler can decide: skipping is
 * usually right, since the run in flight is doing the work. Retrying is not — the
 * conflict will still be there — so a handler that lets this escape should wrap it
 * in Messenger's UnrecoverableMessageHandlingException rather than burn the retries.
 *
 * If it fires and nothing is actually running, the blocker is a dead worker's
 * orphan row: `app:ingestion:runs --abandon-stale`.
 *
 * PostgreSQL aborts the surrounding transaction on the violation that causes this,
 * so a caller that wraps its ingestion in one has to catch this outside that
 * transaction, not inside it.
 */
final class IngestionAlreadyRunning extends \RuntimeException
{
    public function __construct(
        public readonly IngestionSource $source,
        public readonly ?\DateTimeImmutable $windowStart,
        public readonly ?\DateTimeImmutable $windowEnd,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf(
            'An ingestion run for %s (%s) is already in flight.',
            $source->value,
            null !== $windowStart
                ? \sprintf('%s → %s', $windowStart->format('Y-m-d'), $windowEnd?->format('Y-m-d') ?? '?')
                : 'no window',
        ), previous: $previous);
    }
}
