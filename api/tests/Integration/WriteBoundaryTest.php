<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The write boundary from db/README.md, asserted rather than documented.
 *
 * This is the `symfony` half of the matrix; analytics/tests/test_permissions.py
 * owns the `analytics` half. It goes through the kernel's own Doctrine
 * connection on purpose: the claim under test is not "the grants are right" but
 * "the connection this application actually opens is the constrained one". A
 * DSN that quietly reverted to the superuser would pass a psql-based check and
 * fail this one.
 *
 * Cross-role writes that need a table another role owns (INSERT into an
 * existing mart table) are unreachable from a single connection; the CI job in
 * .github/workflows/ci.yml drives both roles and covers those.
 */
final class WriteBoundaryTest extends KernelTestCase
{
    /**
     * SQLSTATE insufficient_privilege. Matching the code rather than the
     * message keeps this stable across PostgreSQL versions and locales.
     */
    private const INSUFFICIENT_PRIVILEGE = '42501';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);

        // Never committed. These probes run against the live contract database,
        // and PostgreSQL rolls DDL back like anything else, so the schemas are
        // left exactly as they were found.
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testConnectsAsTheSymfonyRole(): void
    {
        // Everything below is meaningless if the DSN drifted to the superuser.
        self::assertSame('symfony', $this->connection->fetchOne('SELECT current_user'));
    }

    public function testWritesItsOwnRawLayer(): void
    {
        $this->connection->executeStatement('CREATE TABLE raw.permission_probe (id INT)');
        $this->connection->executeStatement('INSERT INTO raw.permission_probe (id) VALUES (1)');
        $this->connection->executeStatement('UPDATE raw.permission_probe SET id = 2');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM raw.permission_probe'));

        $this->connection->executeStatement('DELETE FROM raw.permission_probe');
    }

    public function testWritesItsOwnCanonicalLayer(): void
    {
        $this->connection->executeStatement('CREATE TABLE canonical.permission_probe (id INT)');
        $this->connection->executeStatement('INSERT INTO canonical.permission_probe (id) VALUES (1)');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM canonical.permission_probe'));
    }

    public function testCannotCreateTableInMart(): void
    {
        // mart has no tables until #6, so CREATE is the only write into another
        // role's layer that is reachable from this connection today. The
        // privilege assertions below cover the DML that will arrive with them.
        $this->assertDenied('CREATE TABLE mart.intruder (id INT)');
    }

    public function testHasNoCreatePrivilegeOnMart(): void
    {
        self::assertFalse($this->hasSchemaPrivilege('mart', 'CREATE'), 'symfony can add tables to mart');
        self::assertTrue($this->hasSchemaPrivilege('mart', 'USAGE'), 'symfony cannot see into mart, so reads are broken');
    }

    public function testFutureMartTablesAreReadableButNotWritable(): void
    {
        // The ALTER DEFAULT PRIVILEGES in db/init/02-schemas.sql is what keeps
        // the read side working without a manual GRANT after every analytics
        // migration. Asserting the whole privilege set, not just SELECT, is
        // what makes this a boundary test: anything more than SELECT here is a
        // silent write path into mart for every table analytics creates.
        self::assertSame(['SELECT'], $this->defaultPrivilegesFor('mart'));
    }

    private function assertDenied(string $sql): void
    {
        try {
            $this->connection->executeStatement($sql);
        } catch (DriverException $e) {
            self::assertSame(
                self::INSUFFICIENT_PRIVILEGE,
                $e->getSQLState(),
                sprintf('expected a permission denial, got: %s', $e->getMessage()),
            );

            return;
        }

        self::fail(sprintf('expected PostgreSQL to deny: %s', $sql));
    }

    private function hasSchemaPrivilege(string $schema, string $privilege): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT has_schema_privilege(current_user, ?, ?)',
            [$schema, $privilege],
        );
    }

    /**
     * Privileges symfony will hold on tables created in $schema from now on.
     *
     * @return list<string>
     */
    private function defaultPrivilegesFor(string $schema): array
    {
        return $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT a.privilege_type
                FROM pg_default_acl d
                JOIN pg_namespace n ON n.oid = d.defaclnamespace
                CROSS JOIN aclexplode(d.defaclacl) a
                WHERE n.nspname = ?
                  AND d.defaclobjtype = 'r'
                  AND a.grantee = current_user::regrole
                ORDER BY 1
                SQL,
            [$schema],
        );
    }
}
