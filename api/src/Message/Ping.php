<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Scaffolding (#8): proves a message dispatched from the console reaches the
 * worker container. Deleted in #5 with the first real ingestion message.
 *
 * `fail` exists only to exercise the retry + failure transport by hand. It
 * implements IngestionMessage so the interface routing is proven now, not first
 * discovered in #5.
 */
final readonly class Ping implements IngestionMessage
{
    public function __construct(
        public string $note = 'ping',
        public bool $fail = false,
    ) {
    }
}
