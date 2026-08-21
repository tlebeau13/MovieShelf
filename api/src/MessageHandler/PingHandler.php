<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\Ping;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Scaffolding (#8): see App\Message\Ping. Deleted in #5.
 */
#[AsMessageHandler]
final readonly class PingHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Ping $ping): void
    {
        if ($ping->fail) {
            // Proves the retry strategy and the failure transport: after the
            // configured retries this lands in `failed` for `make messenger-retry`.
            throw new \RuntimeException(sprintf('Ping asked to fail: %s', $ping->note));
        }

        $this->logger->info('Ping handled by worker: {note}', ['note' => $ping->note]);
    }
}
