<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();
        $healthy = 'ok' === $database;

        $response = new JsonResponse(
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => [
                    'database' => $database,
                ],
            ],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );

        // Load balancers and proxies must never serve a stale verdict.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Round-trips a query so we test the connection as the restricted "symfony"
     * role, not just that the TCP port is open.
     */
    private function checkDatabase(): string
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            // Deliberately not surfaced: the DSN and driver internals leak in
            // Doctrine exception messages, and this endpoint is unauthenticated.
            return 'error';
        }

        return 'ok';
    }
}
