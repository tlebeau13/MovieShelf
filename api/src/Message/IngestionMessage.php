<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Marker for anything that drives an ingestion run (#5/#6/#7).
 *
 * Messenger routes by interface, so a new source's message reaches the worker by
 * implementing this — no routing line to remember in messenger.yaml, and no message
 * that silently stays synchronous because the line was forgotten.
 */
interface IngestionMessage
{
}
