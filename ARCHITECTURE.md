# Architecture

The decisions behind MovieShelf, and why they were made. What the project is for,
what it is trying to achieve and the delivery phases live in
[README.md](README.md); this document is only the technical reasoning.

---

## 1. Four components, one database

| Directory | Stack | Owns |
|---|---|---|
| `api/` | Symfony 8.1, PHP 8.5, FrankenPHP | Ingestion, canonical entities, REST API |
| `analytics/` | Python 3.12, uv, supercronic | Recomputation: reads RAW, writes MART |
| `web/` | Next.js 16, TypeScript, Recharts | Dashboard |
| `db/` | PostgreSQL 16 | The contract between the services |

```
                    ┌───────────────── api/ (Symfony · FrankenPHP) ─────────────────┐
                    │                                                                │
 TMDB ┐             │   HTTP Client  →  ingestion jobs (Scheduler / Messenger)       │
 NYT  ├──────────►  │                              │                                 │
 OL   ┘             │                              ▼                                 │
                    │                    writes  raw. + canonical.                    │
                    │                    reads   mart.                                │
                    └───────────────┬───────────────────────────────┬────────────────┘
                                    │                               │
                    ┌───────────────▼───────── PostgreSQL ──────────▼────────────────┐
                    │   schema raw.        schema canonical.        schema mart.      │
                    │   owner: symfony     owner: symfony           owner: analytics  │
                    └───────────────▲───────────────────────────────▲────────────────┘
                                    │                               │
                    ┌───────────────┴──── analytics/ (Python) ──────┴────────────────┐
                    │   pandas aggregations, clustering, stats, fuzzy matching        │
                    │   reads  raw. + canonical.        writes  mart.                 │
                    └────────────────────────────────────────────────────────────────┘

                    ┌──────────────────── web/ (Next.js) ───────────────────────────┐
                    │   Talks to api/ over HTTP only. No database driver, ever.      │
                    └────────────────────────────────────────────────────────────────┘
```

---

## 2. The seam: a shared database, not HTTP

The contact point between Symfony and Python is **PostgreSQL, not an HTTP call**.

A shared-database seam was chosen over an HTTP API between the services because
the pipeline is batch, not real-time: the database *is* the contract, and the
RAW/canonical/MART split is a genuine data engineering decomposition rather than a
transport choice.

- **RAW layer** (written by Symfony) — the ingested snapshots, untouched.
- **Canonical layer** (written by Symfony) — deduplicated, enriched entities.
- **MART layer** (written by Python) — derived tables, shaped for the dashboard.

Symfony reads the marts to serve the API. Neither service writes into the other's
layer.

The cost of this choice is that nothing is immediate: an analysis reflects the last
ingestion, not the current one. For a weekly bestseller list that is not a
limitation, which is exactly why the seam is defensible here and would not be in a
system with real-time expectations.

---

## 3. Layers are schemas, not table prefixes

Each layer is a **Postgres schema** (`raw.nyt_snapshot`), not a name prefix
(`raw_nyt_snapshot`). This is the decision the whole boundary rests on: a prefix is
a naming convention and carries no authority, whereas a schema can be *owned* and
can carry grants. Ownership is what turns "we agreed not to write there" into "the
database refuses".

A fourth schema, `migrations`, holds Doctrine's `doctrine_migration_versions`
bookkeeping table. It is tooling state, not data — unqualified it would follow the
`symfony` role's `search_path` straight into `canonical`, and `public` is revoked
deliberately.

---

## 4. The boundary is enforced, not documented

Two login roles, created by `db/init/01-roles.sh`:

| Role | Used by | Writes | Reads |
|---|---|---|---|
| `symfony` | `api/` | `raw`, `canonical`, `migrations` | `mart` |
| `analytics` | `analytics/` | `mart` | `raw`, `canonical` |

Application code never connects as the superuser. Cross-layer reads are
auto-granted on future tables via `ALTER DEFAULT PRIVILEGES`, so a new migration
does not require a manual `GRANT`.

A deliberate attempt to write outside your layer fails at the database, and that
rejection is the design working. It is checked from three angles, all in CI:

| Check | Proves |
|---|---|
| `api/tests/Integration/WriteBoundaryTest.php` | The connection **Symfony itself opens** is the `symfony` role, and is refused on `mart` |
| `analytics/tests/test_permissions.py` | The connection **Python itself opens** is the `analytics` role, and is refused on `raw`/`canonical` |
| `db/verify-contract.sh` (`make db-verify`) | Cross-role writes: neither role can touch a table the *other* one owns |

The split exists because a service's own suite holds a single connection, so it can
never set up the interesting case — a table owned by the other role to be denied on.
`verify-contract.sh` opens both.

Two traps found while building this, both now handled:

- The service suites assert the **entire** privilege set granted by
  `ALTER DEFAULT PRIVILEGES`, not merely that `SELECT` is present. Anything beyond
  `SELECT` would be a write path that opens by itself on the next migration.
- A self-`GRANT` on tables you do not own is a **no-op with a `WARNING`**, not an
  error — the statement "succeeds" while granting nothing. Privilege escalation is
  therefore checked by effect (is the write still refused?), never by exit code.

**Consequence for the analysis layer.** Because `analytics` cannot write
`canonical`, any result computed in Python that belongs to the canonical model
cannot be written there directly. Fuzzy match suggestions are the case that hits
this, and they have to round-trip: `analytics` writes candidates to a `mart` table,
`api/` reads them and promotes accepted ones. That is the boundary imposing a design,
which is the intended behaviour rather than a workaround.

---

## 5. Orchestration

`docker compose`, with separate containers:

- `db` — PostgreSQL 16. `db/init/` runs **once**, on first boot with an empty volume.
- `php` — FrankenPHP (a Caddy-based PHP server; **no nginx, no php-fpm**). Symfony
  **Scheduler** fires the ingestion jobs — TMDB daily, NYT weekly.
- `analytics` — Python on **supercronic** (a container-friendly cron), reading RAW
  and writing MART on its own schedule.
- `web/` is **not** in compose; Next.js runs on the host via `yarn dev`.

Total decoupling through the database: if Python falls over, ingestion carries on.

Both schedulers are timer-driven, so the two sides never coordinate. The more
advanced variant — Symfony dispatching a Messenger message that a Python worker
consumes, triggering analysis on demand — is deliberately *not* built: it would put
a queue between the services and weaken the claim that the database is the only
seam. Worth naming as a next step, not as a shortcut.

---

## 6. Data sources, and the constraints they impose

- **TMDB API v3** — films, genres, `popularity`, `vote_average`. Free key.
- **NYT Books API** — weekly bestseller lists. Free key. This is the source that
  gives the pipeline its point: it changes every week.
- **Open Library API** (Internet Archive) — book **subjects**, publication year,
  cover. No key required. Load-bearing, not decorative: it is the only source of
  *book-level* genre data, so the whole book-versus-film genre comparison depends
  on it.
- **OMDb API** — considered for IMDb rating and box office on top of TMDB. **Not
  adopted.** TMDB's `vote_average` filtered by `vote_count` answers the same
  question at no extra key and no extra rate limit.
- **Goodreads — do not use.** No new developer keys since late 2020. Plenty of
  older tutorials still point at it.

**The history constraint — resolved (#28).** The original premise was that the
pipeline accumulates its own time series, which would have meant a single data
point at launch and a dashboard not worth showing for months. Both sources turn out
to serve history retroactively, so that premise no longer holds.

| Source | History mechanism | Binding constraint |
|---|---|---|
| NYT | `lists/{date}/{list-name}.json` accepts a **past date**, not only `current` | **1,000 requests/day.** 52 weeks × 18 years ≈ 936 requests — one day's entire quota, for one list |
| TMDB genres | `discover/movie` with `primary_release_date.gte/.lte`, bucketed on release date | 20 results/page × 500 pages = **10,000 items per query**, so windows must be partitioned to stay under it |
| TMDB `popularity` | none — genuinely point-in-time | Accumulates forward only |
| Open Library | not time-varying; subjects are a property of the work | — |

Two consequences for the design:

- **Backfill is a resumable, budget-aware batch job, not a single run.** The NYT
  daily quota makes it span days, so it has to track progress and stop when the
  budget is spent. That constraint is imposed by the source, not chosen.
- **Two kinds of series must not be charted as one.** A series derived from record
  dates (a film's release, a list's publication week) is retroactive and stable. A
  series derived from observation dates (`popularity` as sampled by the cron) starts
  when the pipeline does. They answer different questions; conflating them on one
  axis would be a quiet lie.

Open Library detail worth recording: subjects hang off the **work**, not the
edition, so `/isbn/{isbn}.json` redirects to an edition that has none.
`search.json?q={isbn}&fields=…` collapses the two hops into one 1.1 KB request and
is the endpoint to use. Its subject list mixes free keywords with BISAC-style codes
(`FICTION / Thrillers`), and the prefixed entries are the structured ones — which is
what makes mapping book subjects onto TMDB's fixed genre list tractable at all.

---

## 7. Data model

Only the scaffolding tables exist today (`raw.ingest_heartbeat`,
`mart.hello_world`). They exist purely to prove the seam works and are deleted in
#5/#6. Everything below is the target shape.

### RAW — written by Symfony

**`raw.nyt_snapshot`** — one row per book per date
`id`, `list_name`, `rank`, `isbn13`, `title`, `author`, `weeks_on_list`, `snapshot_date`

**`raw.tmdb_snapshot`**
`id`, `tmdb_id`, `media_type`, `title`, `popularity`, `vote_average`, `vote_count`, `rank`, `snapshot_date`

**`raw.openlibrary_snapshot`**
`id`, `isbn13`, `subjects`, `first_publish_year`, `fetched_at`

### Canonical — written by Symfony

**`canonical.book`** — `id`, `isbn13`, `title`, `author`, `first_publish_year`, `open_library_id`, `subjects`

Identity rule: ISBN-13 where the source provides it, falling back to a normalised
`title|author` key. The trade-off is known and accepted — editions with distinct
ISBNs can split into two books, so it needs a spot-check rather than trust.

**`canonical.film`** — `id`, `tmdb_id`, `title`, `original_title`, `release_date`, `runtime`, `genres`, `vote_average`

TMDB's id is authoritative, so film identity needs no heuristic at all.

**`canonical.adaptation`** — `id`, `book_id`, `film_id`, `match_confidence`, `matched_on`

### MART — written by Python

| Table | Holds |
|---|---|
| `mart.rank_series` | One row per (book, week) — the raw material for the line chart |
| `mart.bestseller_longevity` | One summary row per book: weeks charted, first/last seen, best rank |
| `mart.trajectory_archetype` | Cluster assignment per book, with distance |
| `mart.film_genre_trend` | Genre share per period, bucketed on film release date |
| `mart.book_subject_trend` | Subject share per period, bucketed on chart dates |
| `mart.genre_lead_lag` | Per-genre lag between the two series above, with sample size |
| `mart.adaptation_comparison` | Longevity versus film rating, with coefficient and n |
| `mart.adaptation_match_suggestion` | Scored fuzzy candidates awaiting promotion by `api/` |

The `film_genre_trend` / `book_subject_trend` names are deliberate. An earlier
single `mart.genre_trend` was ambiguous between "genres of films" and "genres of
books", and the two are different claims drawn from different sources.

---

## 8. The Symfony / Python split

### Symfony — ingestion, modelling, serving

- Ingestion services via **HTTP Client**, one per source, with error handling and
  rate limiting
- **Scheduler** for cadence, **Messenger** for async work and workers
- **Doctrine** for canonical entities and migrations
- **Rule-based matching** on title, author and year — deterministic business logic,
  and it belongs in PHP
- Promoting accepted match suggestions into `canonical.adaptation`, since only the
  `symfony` role may write there
- The **REST API** serving the dashboard

### Python — introduced for what it genuinely does better

- Reading RAW via **pandas** (and **SQLAlchemy** / `psycopg`)
- **Trajectory clustering** — k-means or DTW over rank curves, to test whether
  bestsellers follow archetypal shapes. Not expressible as a `GROUP BY`
- **Cross-correlation** — lead/lag between book subject trends and film genre
  trends, which is a statistical question with a statistical answer
- **Statistics** — longevity versus adaptation rating, reported with sample size
  and p-value rather than a bare coefficient
- **Fuzzy matching** (`rapidfuzz`) to recover titles that do not match exactly
- Temporal aggregations, where they sit alongside the above rather than travelling
  to Python on their own

> ⚠️ **Résumé-driven-architecture guardrail:** only put Python where it actually
> pays. If the work is basic SQL aggregation, forcing Python through it is
> architecture dictated by a CV — a flaw, not a signal. Clustering, cross-correlation
> and the statistics justify its presence on their own; longevity alone would not
> have.

`scikit-learn` is declared as an **optional** dependency group (`ml`) rather than a
default install, so the runtime image stays small until a recommender earns it.

---

## 9. Stack, as actually built

- **Symfony 8.1**, **PHP 8.5**, served by **FrankenPHP** (not php-fpm + nginx)
- **Doctrine ORM** + **PostgreSQL 16**
- **Symfony HTTP Client, Messenger, Scheduler**
- **Python 3.12**, dependencies managed with **uv**, scheduled by **supercronic**:
  `pandas`, `rapidfuzz`, `SQLAlchemy` / `psycopg`; `scikit-learn` optional
- **Front: Next.js 16 (App Router) + TypeScript + Recharts + Tailwind**, yarn on the
  host. *The original plan offered "Twig + Chart.js" or "a small Vue/React SPA"; a
  Next.js app was chosen for the stronger fullstack signal.*
- **Docker** + `docker compose`
- **Deployment:** undecided — Fly.io / Railway / VPS all still on the table
- **CI:** GitHub Actions — one path-filtered workflow per component (`api`,
  `analytics`, `web`, `db`), so a PR runs the checks of the components it touches:
  PHP-CS-Fixer + PHPUnit, ruff + pytest, eslint + tsc + vitest, shellcheck +
  `verify-contract.sh`, plus a build of each production image (which the dev stack
  never exercises). `make check` runs the same set locally.

Tooling lives in containers, not on the host: composer runs in the `php` container,
pytest and ruff in `analytics`. `web/` is the exception — yarn runs on the host.
`make` with no arguments lists every target.
