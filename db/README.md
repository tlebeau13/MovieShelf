# db/ — the shared PostgreSQL contract

This folder makes the **seam** between Symfony and Python a first-class thing.

The architectural decision: Symfony and Python talk **through PostgreSQL, not HTTP**,
because the pipeline is batch, not real-time. The database *is* the contract.

## Layer ownership (who writes what)

Layers are separated as **three Postgres schemas** (not table-name prefixes), so the
write-boundary is enforced by ownership + grants rather than convention.

| Schema      | Tables (arrive in later issues)                          | Writer / owner | Readers          |
|-------------|----------------------------------------------------------|----------------|------------------|
| `raw`       | `raw.ingestion_run`, `raw.nyt_snapshot`, `raw.tmdb_snapshot`, … | `symfony`      | `analytics` (RO) |
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

## Source coverage (verified, #28)

The historical range each source actually serves — what #12/#13/#17 chart against,
confirmed with live calls, not docs.

| Source | Endpoint | Range | Cadence |
|--------|----------|-------|---------|
| NYT Books | `lists/{date}/{list}.json` | back to **at least 2008-06** (200s from 2008-06-15 on; 2008 boundary not pinned) → present | weekly, `published_date` snaps to Sunday |
| TMDB | `discover/movie` | full catalogue, windowed by `primary_release_date` | n/a (from docs; live check pending key) |

Both serve history retroactively, so backfill is a direct dated crawl, not a
forward-only feed.

### NYT `raw.nyt_snapshot` — field notes for #6

Verified against a live `hardcover-fiction` response (dump: one per endpoint kept
out of git; regenerate with a key). Per-book fields that matter:

- **`primary_isbn13`** — present on every row; the join key to Open Library.
  `primary_isbn10` is sometimes empty — do not rely on it.
- **`weeks_on_list`** — reliably populated (observed 1–40), not sparse.
- **`rank_last_week = 0`** is a **sentinel for "not on the list last week"**, not a
  missing value. Every new entry (`weeks_on_list = 1`) carries it. Trajectory math
  in #12/#17 must treat 0 as absence, not rank zero.
- **Two dates**: `bestsellers_date` (the sales week measured) vs `published_date`
  (when NYT published the list, always a Sunday). `published_date` is the list key.
- **`price` is `"0.00"`** across the board — NYT no longer publishes it. Dead field.

### NYT rate limits — for #6's crawler

Documented: 1,000 requests/day. **Undocumented burst cap observed**: sequential
probes at ~7s spacing drew a `429`. The daily quota already forces a multi-day
resumable job; the burst cap means it also needs per-request throttling and retry
on 429, not just a daily counter.

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
