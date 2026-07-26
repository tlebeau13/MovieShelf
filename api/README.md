# api/ — Symfony 8.1

Owns **ingestion, orchestration, canonical entities, and the REST API**.

## Responsibilities
- HTTP Client ingestion services (one per source: TMDB, NYT, Open Library, OMDb).
- Scheduler: TMDB daily, NYT weekly. Messenger for async workers.
- Doctrine entities + migrations for the **canonical** layer (`book`, `film`, `adaptation`).
- Writes the **RAW / staging** tables (`raw_nyt_snapshot`, `raw_tmdb_snapshot`).
- Rule-based adaptation matching (title + author + year).
- REST API that serves the **MART** tables to the front.

## Ownership rule
Symfony writes **RAW + canonical**. It only **reads** MART.
It must never write MART tables — those belong to `analytics/`.

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
