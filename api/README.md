# api/ — Symfony 8.1

Owns **ingestion, orchestration, canonical entities, and the REST API**.

## Responsibilities
- HTTP Client ingestion services (one per source: TMDB, NYT, Open Library, OMDb).
- Scheduler: TMDB daily, NYT weekly. Messenger for async workers.
- Doctrine entities + migrations for the **canonical** layer (`book`, `film`, `adaptation`).
- Writes the **RAW / staging** tables (`raw.nyt_snapshot`, `raw.tmdb_snapshot`).
- Rule-based adaptation matching (title + author + year).
- REST API that serves the **MART** tables to the front.

## Ownership rule
Symfony writes **RAW + canonical**. It only **reads** MART.
It must never write MART tables — those belong to `analytics/`.

## Ingestion keys

NYT and TMDB both need a key. Three places, one direction:

| Where | Holds | Why |
|-------|-------|-----|
| root `.env` (gitignored) | the real values | one file, read by compose for every service |
| root `.env.example` (committed) | empty placeholders | what a contributor fills in; CI copies it verbatim |
| `api/.env` (committed) | empty defaults | so config references resolve when nothing injects them |

`compose.yaml` passes `NYT_KEY` and `TMDB_KEY` into the php container. Nothing
reads them from `api/.env.local`, and nothing should: `api/.dockerignore` excludes
that file, so it does not exist inside a built image. Putting a key there works in
dev — the bind-mount makes it visible — and silently produces an unauthenticated
container in production.

To deploy, inject the same variables however the host does env vars. Keys never
belong in an image layer or a committed file, which is the part that holds
regardless of where the app ends up running.

### How the suites behave without a key

Split by what is under test, so the bulk of ingestion needs no secret:

- **Parsing, mapping, and error handling** run on recorded fixtures in
  `tests/Fixtures/<provider>/`. These are the majority and always run.
- **Live provider calls** — one thin test per source, asserting the response still
  has the shape the parser expects — use the `RequiresApiKey` trait and skip when
  the key is unset.

CI copies `.env.example`, so the keys are empty and the live tests skip. A fork
with no secrets passes. The live tests are what catch upstream drift: NYT's
`/lists/names.json` already 404s with a valid key, and only a real call finds that.

Trim recorded fixtures before committing — a full `lists/overview.json` response
is ~390 KB.

## Checks

```bash
docker compose exec php bin/phpunit                     # needs a live DB
docker compose exec php vendor/bin/php-cs-fixer check    # report only
docker compose exec php vendor/bin/php-cs-fixer fix      # rewrite
```

`make api-lint` adds `composer validate`, `lint:container` and `lint:yaml` — the
same set CI runs. Coding standards are the `@Symfony` ruleset
(`.php-cs-fixer.dist.php`); the tool is a dev dependency, so it exists in the dev
image only.

## Async workers (Messenger)

Ingestion runs off the web request, as Messenger messages a separate `worker`
container consumes. The pattern is established here (#8) so the ingestion services
(#5–#7) are written as handlers once, not inline and then converted.

- **Transport: Doctrine, not Redis.** Postgres already runs and the pipeline is
  batch, so a queue in a second datastore would buy nothing. The queue tables live
  in their own **`messenger` schema** owned by `symfony` — a work queue is tool
  state, so it stays out of `canonical`, the same call the `migrations` schema
  makes.
- **Setup.** The transport runs `auto_setup=0` (no DDL at dispatch time); the
  tables are created by `messenger:setup-transports`, which the container
  entrypoint runs at boot after migrations. `make messenger-setup` runs it by hand.
- **Cadence.** The Scheduler (`src/Schedule.php`) dispatches messages; the worker
  just consumes `async` and `scheduler_default`. Cadence stays declarative in one
  place, the worker stays a dumb consumer.
- **Failure.** `async` retries 3× with exponential backoff; a message still failing
  lands in the `failed` transport instead of vanishing. Inspect with
  `make messenger-failed`, retry by hand with `make messenger-retry` (all, or
  `CMD=<id>` for one).
- **Logs.** `make worker-logs` prints a line per message — received, handled
  successfully, each retry, and the hand-off to the failure transport — with the
  channel in front (`messenger.INFO`, `app.INFO`). Levels come from Monolog, not
  from console verbosity, so the consumer takes no `-v` flags.
- **The worker recycles itself** on a time/memory limit and `restart:
  unless-stopped` brings it back — the supported way to run a long-lived worker.
  SIGTERM stops it between messages, so an in-flight handler finishes first.

Prove the seam without any real ingestion: `bin/console app:ping` dispatches a
scaffolding message; `--fail` drives the retry + failure path. Both `app:ping` and
its handler are labelled scaffolding and are deleted in #5.

```bash
make worker-logs        # follow the consumer
make api-console CMD="app:ping --note=hi"   # dispatch a test message
make messenger-failed   # what died
```

## Ingestion runs (#29)

Every ingestion service records what it did in `raw.ingestion_run` — one shared
shape for #5/#6/#7, documented in `db/README.md`. Go through the recorder rather
than writing the table:

```php
$this->runs->record(
    IngestionSource::Nyt,
    function (IngestionRun $run): IngestionResult {
        // ... fetch, write to raw.nyt_snapshot ...
        return new IngestionResult($rowsWritten, ['requests' => $calls]);
    },
    windowStart: $week,
    windowEnd: $week,
);
```

The closure returns an `IngestionResult`, not an int, so a job cannot report its page
count as its row count and have it typecheck.

`record()` writes the `running` row before the work starts, marks it `success` or
`failed` afterwards, and **re-throws** — recording a failure must not swallow it, or
Messenger's retry policy never fires. Use `start()` + `succeed()`/`fail()` directly
when a job needs to report progress mid-run.

Terminal states are written over DBAL rather than the EntityManager on purpose: a
handler that throws mid-flush leaves the EntityManager closed, and a closed
EntityManager cannot persist the row that records why.

`start()` throws `IngestionAlreadyRunning` when another attempt at the same window is
in flight — the database enforces that, not the caller (see `db/README.md`). Skip on
it; do not retry, since the conflict will still be there. If it fires and nothing is
actually running, a dead worker's row is holding the window: `--abandon-stale`.

```bash
make ingestion-runs                      # last 20 attempts; exits non-zero if the
                                         # latest attempt for a source failed or is stale
make ingestion-runs CMD="--source=tmdb --limit=5"
make ingestion-runs CMD="--abandon-stale"    # release windows held by dead workers
```

## Logging

Monolog, configured for containers rather than for a log directory
(`config/packages/monolog.yaml`).

- **dev** writes two ways: INFO and up to `stderr`, which is what `make up` and
  `make worker-logs` show, and the full DEBUG trail to `var/log/dev.log`. The
  `event` and `doctrine` channels are off the `stderr` handler — every listener
  notification and every SQL query, otherwise ~18 lines per request. They stay in
  the file, so `tail -f api/var/log/dev.log` still has them when a request needs
  taking apart.
- **prod** buffers: nothing is written until something fails, then `fingers_crossed`
  flushes the whole DEBUG trail that led there, as JSON on `stderr` for Docker to
  collect. Deprecations get their own channel and bypass the buffer.

Caddy's access log is `wrap console` with the header, TLS and response-header dumps
filtered out (`frankenphp/Caddyfile`), so a request is one readable line instead of
~700 characters of JSON.

## Stack
Symfony 8.1, PHP 8.5, Doctrine ORM 3, Messenger, Scheduler, Monolog. HTTP Client arrives
with the ingestion services (#5–#7) — it is not a dependency yet.
Served by **FrankenPHP** (single container, built on Caddy — no separate nginx +
php-fpm). Automatic HTTPS on deploy; worker mode available as a later perf step.
