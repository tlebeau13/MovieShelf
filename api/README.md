# api/ — Symfony 7

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

## Stack
Symfony 7.x, PHP 8.3+, Doctrine ORM, HTTP Client, Messenger, Scheduler.
Served by **FrankenPHP** (single container, built on Caddy — no separate nginx +
php-fpm). Automatic HTTPS on deploy; worker mode available as a later perf step.
