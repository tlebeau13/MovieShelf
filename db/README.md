# db/ — the shared PostgreSQL contract

This folder makes the **seam** between Symfony and Python a first-class thing.

The architectural decision: Symfony and Python talk **through PostgreSQL, not HTTP**,
because the pipeline is batch, not real-time. The database *is* the contract.

## Layer ownership (who writes what)

Layers are separated as **three Postgres schemas** (not table-name prefixes), so the
write-boundary is enforced by ownership + grants rather than convention.

| Schema      | Tables (arrive in later issues)                          | Writer / owner | Readers          |
|-------------|----------------------------------------------------------|----------------|------------------|
| `raw`       | `raw.nyt_snapshot`, `raw.tmdb_snapshot`                  | `symfony`      | `analytics` (RO) |
| `canonical` | `canonical.book`, `canonical.film`, `canonical.adaptation` | `symfony`    | `analytics` (RO) |
| `mart`      | `mart.rank_series`, `mart.bestseller_longevity`, `mart.film_genre_trend`, … | `analytics` | `symfony` (RO) |

A fourth schema, `migrations`, holds Doctrine's `doctrine_migration_versions`
bookkeeping table. It is tooling state, not data: unqualified it would follow the
`symfony` role's `search_path` straight into `canonical`, and `public` is revoked
on purpose. See `api/config/packages/doctrine_migrations.yaml`.

**No service writes into another layer's schema.** This is enforced by Postgres:
`symfony` has no CREATE/INSERT on `mart`, `analytics` has none on `raw`/`canonical`.
Read access to the other layer is auto-granted on future tables via
`ALTER DEFAULT PRIVILEGES` (see `init/02-schemas.sql`).

### Where that claim is tested

The boundary is checked from three angles, all of which run in CI:

| What | Proves |
|------|--------|
| `api/tests/Integration/WriteBoundaryTest.php` | The connection **Symfony itself opens** is the `symfony` role, and it is refused on `mart` |
| `analytics/tests/test_permissions.py`         | The connection **Python itself opens** is the `analytics` role, and it is refused on `raw`/`canonical` |
| `verify-contract.sh` (`make db-verify`)       | Cross-role writes: neither role can touch a table the *other* one owns |

The split exists because a service's own suite holds one connection, so it can
never set up the interesting case — a table owned by the other role to be denied
on. `verify-contract.sh` opens both, and is the only one that can.

Two traps worth knowing, both already handled:

- The service suites assert the **whole** privilege set that
  `ALTER DEFAULT PRIVILEGES` hands out, not just that `SELECT` is present.
  Anything beyond `SELECT` there is a write path that opens by itself on the
  next migration.
- A self-`GRANT` on tables you do not own is a **no-op with a `WARNING`**, not an
  error. Escalation is therefore checked by effect (is the write still refused?)
  rather than by exit code.

## Roles & connection strings

Two login roles, created by `init/01-roles.sh` from `.env`:

| Role        | Used by      | Example DSN                                             |
|-------------|--------------|--------------------------------------------------------|
| `symfony`   | `api/`       | `postgresql://symfony:<pw>@db:5432/movieshelf`           |
| `analytics` | `analytics/` | `postgresql://analytics:<pw>@db:5432/movieshelf`         |

(Host is `db` inside the compose network, `localhost` from your machine.)

- **#2 (Doctrine):** map RAW/canonical entities to schema `raw` / `canonical`.
- **#3 (SQLAlchemy):** target schema `mart` for writes; read `raw`/`canonical`.

## Contents
- `init/01-roles.sh` — creates the `symfony` + `analytics` login roles.
- `init/02-schemas.sql` — creates schemas, ownership, cross-read grants, default privileges.
- `verify-contract.sh` — asserts all of the above against a running stack (`make db-verify`).

## ⚠️ Init scripts run ONCE

The postgres image executes `init/` **only on first boot, when the data volume
(`pgdata`) is empty** — alphabetically, `.sh` and `.sql` interleaved by name.
Editing these files does **nothing** on an existing volume. To re-apply from scratch:

```bash
docker compose down -v   # drops the pgdata volume
docker compose up -d db   # re-runs init/
```

DDL for the actual tables is out of scope here: Doctrine migrations in `api/` own
`raw`/`canonical`; the Python service owns `mart` DDL.
