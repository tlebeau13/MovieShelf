-- Runs once, after 01-roles.sh (alphabetical order), as the superuser.
-- This file IS the api <-> analytics contract, expressed as schemas + grants.

-- ── Schemas, each owned by its writer ────────────────────────────────────────
-- Ownership matters: the owner can freely create tables in its schema, and
-- ALTER DEFAULT PRIVILEGES below is keyed to the *creating* role. So owner = writer.
CREATE SCHEMA raw       AUTHORIZATION symfony;    -- Symfony writes ingested snapshots
CREATE SCHEMA canonical AUTHORIZATION symfony;    -- Symfony writes enriched entities
CREATE SCHEMA mart      AUTHORIZATION analytics;  -- Python writes analytics tables

-- Tooling bookkeeping, not data. Doctrine's migration_versions table lives here:
-- unqualified it would follow symfony's search_path into `canonical`, and `public`
-- is revoked below on purpose. Keeping it separate means neither data layer carries
-- tool state. See api/config/packages/doctrine_migrations.yaml.
CREATE SCHEMA migrations AUTHORIZATION symfony;

-- Same reasoning for Messenger's transport tables: a work queue is tool state,
-- not a domain layer, so it gets its own schema instead of landing in `canonical`
-- via search_path. Owned by symfony (the only role that runs the workers).
-- See api/config/packages/messenger.yaml.
CREATE SCHEMA messenger AUTHORIZATION symfony;

-- ── Cross-layer READ access (USAGE lets a role "see into" a schema) ───────────
GRANT USAGE ON SCHEMA raw, canonical TO analytics; -- Python reads Symfony's layers
GRANT USAGE ON SCHEMA mart           TO symfony;   -- Symfony reads Python's marts

-- ── Auto-grant SELECT on FUTURE tables ───────────────────────────────────────
-- These schemas have no tables yet (they arrive in later issues). Default
-- privileges say: whenever <role> creates a table in <schema>, auto-grant SELECT
-- to the other service. This is what keeps the read side working without a manual
-- GRANT after every migration.
ALTER DEFAULT PRIVILEGES FOR ROLE symfony   IN SCHEMA raw       GRANT SELECT ON TABLES TO analytics;
ALTER DEFAULT PRIVILEGES FOR ROLE symfony   IN SCHEMA canonical GRANT SELECT ON TABLES TO analytics;
ALTER DEFAULT PRIVILEGES FOR ROLE analytics IN SCHEMA mart      GRANT SELECT ON TABLES TO symfony;

-- ── Convenience: default schema resolution order per role ─────────────────────
-- So an unqualified table name resolves to the role's own layer first.
ALTER ROLE symfony   SET search_path = canonical, raw, public;
ALTER ROLE analytics SET search_path = mart, public;

-- ── Tidy up the default public schema (we don't store data there) ─────────────
REVOKE ALL ON SCHEMA public FROM PUBLIC;
