<?php

declare(strict_types=1);

namespace App\Ingestion;

/**
 * What an ingestion job has to report back (#29): how many rows it landed, and
 * whatever source-specific counters are worth keeping.
 *
 * A type rather than a bare int, so a job cannot report its page count as its row
 * count and have it typecheck.
 */
final readonly class IngestionResult
{
    /**
     * @param array<string, mixed> $details Source-specific counters — requests made,
     *                                      429s absorbed, pages walked
     */
    public function __construct(
        public int $rowsWritten,
        public array $details = [],
    ) {
        if ($rowsWritten < 0) {
            throw new \InvalidArgumentException('An ingestion run cannot write a negative number of rows.');
        }
    }
}
