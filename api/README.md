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

## Stack
Symfony 8.1, PHP 8.5, Doctrine ORM 3, Messenger, Scheduler. HTTP Client arrives
with the ingestion services (#5–#7) — it is not a dependency yet.
Served by **FrankenPHP** (single container, built on Caddy — no separate nginx +
php-fpm). Automatic HTTPS on deploy; worker mode available as a later perf step.
