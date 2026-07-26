<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Skips a test when the ingestion key it needs is absent (#27).
 *
 * The counterpart to the DATABASE_URL skipif in analytics/tests/test_permissions.py,
 * and it exists for the same reason: CI on a fork has no secrets, and a suite that
 * fails there is a suite nobody outside the repo can run.
 *
 * Reach for this only in tests that genuinely call the provider. Anything that
 * parses or maps a response belongs on a recorded fixture instead, so the bulk of
 * ingestion stays covered with no key at all — see api/README.md.
 */
trait RequiresApiKey
{
    private function requireApiKey(string $name): string
    {
        $key = $_SERVER[$name] ?? $_ENV[$name] ?? '';

        if ('' === $key) {
            self::markTestSkipped(sprintf('%s is unset; live-provider test skipped', $name));
        }

        return $key;
    }
}
