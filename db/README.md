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

## `raw.ingestion_run` — the shared run record (#29)

Every ingestion service (#5 TMDB, #6 NYT, #7 Open Library) writes this one table
instead of three log formats, so "did last night's ingestion work?" is one query.
Written through `App\Ingestion\IngestionRunRecorder`, never by hand.

| Column | Type | Meaning |
|--------|------|---------|
| `id` | `BIGINT` identity | |
| `source` | `VARCHAR(32)` | `tmdb` \| `nyt` \| `openlibrary` (`App\Enum\IngestionSource`) |
| `status` | `VARCHAR(16)` | `running` \| `success` \| `failed` |
| `started_at` / `finished_at` | `TIMESTAMPTZ` | `finished_at` is null while running |
| `rows_written` | `INT` | Rows the attempt actually landed in RAW |
| `window_start` / `window_end` | `DATE`, nullable | The slice of source history covered — a NYT list week, a TMDB release window. Null for sources crawled by key rather than by date |
| `error_class` | `VARCHAR(255)`, nullable | The exception's class, kept apart from its message so failures are countable by kind — "how many 503s this week" is a `GROUP BY`, not a `LIKE` |
| `error` | `TEXT`, nullable | The exception's message, truncated to 1000 chars. Never a stack trace |
| `details` | `JSONB` | Source-specific counters (requests made, 429s absorbed) that are not worth a column each |

**A row is one attempt, not one logical run.** When Messenger retries a failed
handler (#8), the new attempt gets its own row. Collapsing them would mean either
losing the failures or rewriting history on retry — and the failure count is the
only evidence that the retry policy in `messenger.yaml` is set right.

### One live attempt per window

`uniq_ingestion_run_active` is a unique index on `(source, window_start, window_end)`
**partial on `status = 'running'`**. A queue redelivers — a worker that dies after
handling but before acking gets the same message again — so two concurrent runs over
one window is a matter of when, not if, and the caller remembering to check is not a
guarantee. Partial on `running` because a retry is a *new row* by design: finished
attempts must be free to pile up on the same window.

Two consequences worth knowing:

- The recorder turns the violation into `IngestionAlreadyRunning`. Skipping is
  usually the right response; retrying is not, since the conflict will still be
  there. PostgreSQL aborts the surrounding transaction on the violation, so catch it
  outside any transaction of your own.
- **Null windows are exempt.** Every `NULL` is distinct in PostgreSQL, so a source
  crawled by key rather than by date can still have two attempts in flight.
  `NULLS NOT DISTINCT` would fix it but Doctrine's mapping cannot express it, and a
  schema that reads as permanently drifted costs more than it buys. A keyed source
  that wants the guarantee passes a window — a synthetic day is enough.

### What a failed run means

- **`failed`** — the handler threw. The exception is re-thrown after the row is
  written, so Messenger still retries; after `max_retries` the message parks in the
  failure transport (`make messenger-failed`) and the last row stays `failed`.
- **No `partial`.** A run that wrote 900 of 1000 rows and then threw is `failed`
  with `rows_written = 900`. A status that needs interpreting is not an answer.
- **`running` past the job's cadence is a dead worker**, not a slow one: the row is
  written before the work starts and nothing rolls it back, so a killed container
  leaves it behind. `app:ingestion:runs` shows those as `stale` (default: older than
  24h) and exits non-zero. Nothing reaps them automatically — the row *is* the
  evidence, and it holds the window under the unique index until someone says the run
  is over: `make ingestion-runs CMD="--abandon-stale"` marks them failed with
  `error_class = AbandonedRun`, keeping the trace while releasing the window.
- **The scheduler does not read this table.** Cadence stays declarative in
  `api/src/Schedule.php` (#8); a run that failed is retried by Messenger, not
  rescheduled. Backfill gaps are a query over `window_start`/`window_end`, which is
  #6's problem, not the scheduler's.

A new source costs one `IngestionSource` case plus its own RAW table: `source` is a
`VARCHAR`, not a PostgreSQL enum, so no migration; the command and the repository
walk `IngestionSource::cases()`; and its message routes itself to the worker by
implementing `App\Message\IngestionMessage` (`messenger.yaml` routes the interface,
not the class).

```bash
make ingestion-runs                          # last 20 attempts, all sources
make ingestion-runs CMD="--source=nyt"       # one source; verdict scoped to it
make ingestion-runs CMD="--abandon-stale"    # release windows held by dead workers
```

## Source coverage (verified, #28)

The historical range each source actually serves — what #12/#13/#17 chart against,
confirmed with live calls, not docs.

| Source | Endpoint | Range | Cadence |
|--------|----------|-------|---------|
| NYT Books | `lists/{date}/{list}.json` | back to **at least 2008-06** (200s from 2008-06-15 on; 2008 boundary not pinned) → present | weekly, `published_date` snaps to Sunday |
| TMDB | `discover/movie` | full catalogue, windowed by `primary_release_date` | n/a (from docs; live check pending key) |

Both serve history retroactively, so backfill is a direct dated crawl, not a
forward-only feed.

### NYT `raw.nyt_snapshot` — as built (#6)

```
id, run_id → raw.ingestion_run(id), list_name, rank, rank_last_week,
isbn13, title, author, weeks_on_list, published_date
```

Unique on (`list_name`, `published_date`, `isbn13`) and written with
`ON CONFLICT DO UPDATE`, so crawling a week twice updates its rows rather than
duplicating them — the property the weekly job and the backfill both lean on.
Indexed on (`isbn13`, `published_date`) for the rank-over-time reads in #12/#17.

The field notes that shaped it:

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

### NYT rate limits — what the crawler does about them

Documented: 1,000 requests/day. **Undocumented burst cap observed**: sequential
probes at ~7s spacing drew a `429`. The daily quota already forces a multi-day
resumable job; the burst cap means it also needs per-request throttling and retry
on 429, not just a daily counter.

Both are handled, in different places (#6): `NytClient` spaces requests 12s apart
(`nyt.min_request_interval`), and the scoped client in `api/config/packages/framework.yaml`
retries 429 with backoff, honouring `Retry-After` when NYT sends one. The backfill's
`--budget` is the daily counter, defaulting below the quota because a retried
request spends quota the counter never sees.

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
