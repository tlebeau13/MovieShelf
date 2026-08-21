<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Scaffolding (#8): proves a message dispatched from the console reaches the
 * worker container. Deleted in #5 with the first real ingestion message.
 *
 * `fail` exists only to exercise the retry + failure transport by hand.
 */
final readonly class Ping
{
    public function __construct(
        public string $note = 'ping',
        public bool $fail = false,
    ) {
    }
}
