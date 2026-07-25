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
| `mart`      | `mart.bestseller_longevity`, `mart.genre_trend`, …       | `analytics`    | `symfony` (RO)   |

**No service writes into another layer's schema.** This is enforced by Postgres:
`symfony` has no CREATE/INSERT on `mart`, `analytics` has none on `raw`/`canonical`.
Read access to the other layer is auto-granted on future tables via
`ALTER DEFAULT PRIVILEGES` (see `init/02-schemas.sql`).

## Roles & connection strings

Two login roles, created by `init/01-roles.sh` from `.env`:

| Role        | Used by      | Example DSN                                             |
|-------------|--------------|--------------------------------------------------------|
| `symfony`   | `api/`       | `postgresql://symfony:<pw>@db:5432/cineshelf`           |
| `analytics` | `analytics/` | `postgresql://analytics:<pw>@db:5432/cineshelf`         |

(Host is `db` inside the compose network, `localhost` from your machine.)

- **#2 (Doctrine):** map RAW/canonical entities to schema `raw` / `canonical`.
- **#3 (SQLAlchemy):** target schema `mart` for writes; read `raw`/`canonical`.

## Contents
- `init/01-roles.sh` — creates the `symfony` + `analytics` login roles.
- `init/02-schemas.sql` — creates schemas, ownership, cross-read grants, default privileges.

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
