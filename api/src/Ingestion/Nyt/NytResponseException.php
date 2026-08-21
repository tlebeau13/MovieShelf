<?php

declare(strict_types=1);

namespace App\Ingestion\Nyt;

/**
 * NYT answered, but not with a list this code can write down (#6).
 *
 * Distinct from the transport failures Symfony's HttpClient throws: those are worth
 * a Messenger retry, and this usually is not — a changed response shape will still
 * be changed on the third attempt. The live shape test exists to catch that drift
 * before the scheduler does.
 */
final class NytResponseException extends \RuntimeException
{
}
