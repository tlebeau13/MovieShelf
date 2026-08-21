# analytics/ — Python

Owns the **analysis brick**: reads RAW, computes, writes MART.

## Responsibilities
- Read the RAW / staging tables via pandas (SQLAlchemy / psycopg).
- Temporal aggregations: bestseller longevity, genre trends per period.
- **Fuzzy matching** (`rapidfuzz`) to improve book ↔ adaptation links — this is
  where Python earns its place, not basic SQL aggregation.
- Stats: book longevity ↔ adaptation rating correlations.
- (Optional) recommendations via subject similarity (`scikit-learn`).
- Writes the **MART / analytics** tables (`mart_*`).

## Ownership rule
Python **reads RAW/canonical** and **writes MART only**.
It must never write RAW or canonical tables — those belong to `api/`.

## Stack
Python 3.12, pandas, rapidfuzz, SQLAlchemy/psycopg, scikit-learn (optional).
Runs on its own schedule (container cron), decoupled from ingestion via the DB.

Dependencies are managed with **uv** (`pyproject.toml` + `uv.lock`). `scikit-learn`
sits behind the `ml` extra so it stays out of the runtime image until #15 needs it.

## Layout
```
crontab              supercronic schedule, one line per job
src/analytics/db.py  engine + advisory-lock helper (connects as the `analytics` role)
src/analytics/jobs/  one module per scheduled job
tests/               permission-boundary tests (need a live DB)
```

## Scheduling
**supercronic** reads `crontab` and logs each run to stdout, so
`docker compose logs analytics` is the whole story. It has no overlap protection
of its own — every job takes a Postgres **advisory lock** (`db.job_lock`) and
skips the tick if a previous run is still going.

## Running

```bash
docker compose up -d analytics       # starts the scheduler
docker compose exec analytics <job>   # run a job now, without waiting for cron
docker compose logs -f analytics     # watch scheduled runs
docker compose exec analytics python -m pytest   # permission tests
docker compose exec analytics ruff check .
docker compose exec analytics ruff format --check .
```

`make analytics-lint` runs both ruff passes, `make analytics-fix` applies them.
Formatting is `ruff format` rather than black: it is black-compatible output from
a tool already in the lockfile.

The `DATABASE_URL` is injected by `compose.yaml` and connects as the restricted
`analytics` role — RO on `raw`/`canonical`, RW on `mart`. There is no code
enforcing that boundary; PostgreSQL grants do (see `db/init/02-schemas.sql`), and
`tests/test_permissions.py` asserts it.

## No jobs yet

The `hello` scaffolding and `raw.ingest_heartbeat` are gone as of #6, which
replaced them with a real RAW table (`raw.nyt_snapshot`) for the permission tests
to read. `crontab` is therefore empty until the first real job lands in #12–#14 —
supercronic keeps running with nothing scheduled, so the container stays up.

One leftover the code cannot clean up: `mart.hello_world`, created at runtime by
the job that no longer exists. `mart` is owned by the `analytics` role, so no
Symfony migration may drop it. On an existing database, drop it by hand:

```bash
make psql-analytics   # then: DROP TABLE IF EXISTS mart.hello_world;
```

or simply `make db-reset`, which rebuilds the volume from `db/init`.
