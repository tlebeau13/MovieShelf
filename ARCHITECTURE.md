# MovieShelf — Film & Book Trend Tracker

**Portfolio project: a hybrid Symfony + Python data pipeline.**

A pipeline that ingests film trends (TMDB) and book bestsellers (New York Times)
on a daily/weekly cadence, accumulates its own time series, links books to their
screen adaptations, and serves an analytics dashboard. Symfony orchestrates and
serves; Python analyses.

---

## 1. The pitch, in one sentence

> "A pipeline that builds a base of cultural trends over time, links books to
> their adaptations, and is architected polyglot: Symfony for ingestion and
> serving, Python for analysis — each tool on its own ground."

What the project is meant to **signal**: command of the stack, data engineering
literacy, and above all **architectural judgement** — the right tool in the right
place.

---

## 2. What the project demonstrates

| Skill | Where it shows up |
|---|---|
| Data engineering / ETL | Scheduled ingestion, transformation, RAW → canonical → MART modelling |
| Orchestration | Symfony Scheduler + Messenger, decoupled jobs |
| Entity matching | Book ↔ adaptation linking (the hard part, and the interesting one) |
| Polyglot + architectural judgement | A clean, justified Symfony / Python split |
| Fullstack | REST API + visualization dashboard |
| Production literacy | Docker, CI, deployment, observability, tests |

---

## 3. Data sources (verified, free)

- **TMDB API v3** — films/series/people; `trending`, `popular`, `movie/{id}`
  endpoints. Free key.
- **NYT Books API** — weekly bestseller lists (`lists/current/{list}`). Free key.
  *This is the source that gives the pipeline its point: it changes every week.*
- **Open Library API** (Internet Archive) — book enrichment (publication year,
  subjects, cover). No key required.
- **OMDb API** — considered for IMDb rating / box office on top of TMDB. **Not
  adopted**; no issue tracks it. Revisit only if TMDB proves insufficient.

⚠️ **Do not use Goodreads.** No new developer keys have been issued since late
2020. Plenty of older tutorials still point at it.

---

## 4. Architecture

Four components, one Postgres database.

| Directory | Stack | Owns |
|---|---|---|
| `api/` | Symfony 8.1, PHP 8.5, FrankenPHP | Ingestion, canonical entities, REST API |
| `analytics/` | Python 3.12, uv, supercronic | Recomputation: reads RAW, writes MART |
| `web/` | Next.js 16, TypeScript, Recharts | Dashboard |
| `db/` | PostgreSQL 16 | The contract between the services |

### The separation principle

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
                    │   pandas aggregations, rapidfuzz matching, stats, recommender  │
                    │   reads  raw. + canonical.        writes  mart.                 │
                    └────────────────────────────────────────────────────────────────┘

                    ┌──────────────────── web/ (Next.js) ───────────────────────────┐
                    │   Talks to api/ over HTTP only. No database driver, ever.      │
                    └────────────────────────────────────────────────────────────────┘
```

### The seam: a shared database, not HTTP

The contact point between Symfony and Python is **PostgreSQL, not an HTTP call**.
That is the right choice for a batch pipeline, and it teaches a real data
engineering pattern:

- **RAW layer** (written by Symfony) — the ingested snapshots, untouched.
- **Canonical layer** (written by Symfony) — deduplicated, enriched entities.
- **MART layer** (written by Python) — derived tables, shaped for the dashboard.

Symfony reads the marts to serve the API. Neither service writes into the other's
layer.

> **For the README:** "I chose a shared-database seam over an HTTP API between
> services because the pipeline is batch, not real-time: the database *is* the
> contract, and the RAW/canonical/MART split reflects a genuine data engineering
> decomposition."

### Layers are schemas, not table prefixes

Each layer is a **Postgres schema** (`raw.nyt_snapshot`), not a name prefix
(`raw_nyt_snapshot`). This is the decision the whole boundary rests on: a prefix
is a naming convention and carries no authority, whereas a schema can be *owned*
and can carry grants. Ownership is what turns "we agreed not to write there" into
"the database refuses".

A fourth schema, `migrations`, holds Doctrine's `doctrine_migration_versions`
bookkeeping table. It is tooling state, not data — unqualified it would follow
the `symfony` role's `search_path` straight into `canonical`, and `public` is
revoked deliberately.

### The boundary is enforced, not documented

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

The split exists because a service's own suite holds a single connection, so it
can never set up the interesting case — a table owned by the other role to be
denied on. `verify-contract.sh` opens both.

Two traps found while building this, both now handled:

- The service suites assert the **entire** privilege set granted by
  `ALTER DEFAULT PRIVILEGES`, not merely that `SELECT` is present. Anything
  beyond `SELECT` would be a write path that opens by itself on the next
  migration.
- A self-`GRANT` on tables you do not own is a **no-op with a `WARNING`**, not an
  error — the statement "succeeds" while granting nothing. Privilege escalation
  is therefore checked by effect (is the write still refused?), never by exit
  code.

### Orchestration

`docker compose`, with separate containers:

- `db` — PostgreSQL 16. `db/init/` runs **once**, on first boot with an empty
  volume.
- `php` — FrankenPHP (a Caddy-based PHP server; **no nginx, no php-fpm**).
  Symfony **Scheduler** fires the ingestion jobs — TMDB daily, NYT weekly.
- `analytics` — Python on **supercronic** (a container-friendly cron), reading
  RAW and writing MART on its own schedule.
- `web/` is **not** in compose; Next.js runs on the host via `yarn dev`.

Total decoupling through the database: if Python falls over, ingestion carries on.

*More advanced variant, worth naming as a next step:* after ingestion, Symfony
dispatches a **Messenger** message that a Python worker consumes, triggering
analysis on demand rather than on a timer.

---

## 5. Data model

Only the scaffolding tables exist today (`raw.ingest_heartbeat`,
`mart.hello_world`); they exist purely to prove the seam works and are deleted in
#5/#6. Everything below is the target shape.

### RAW layer — written by Symfony

**`raw.nyt_snapshot`** — one row per book per date
`id`, `list_name`, `rank`, `isbn13`, `title`, `author`, `weeks_on_list`, `snapshot_date`

**`raw.tmdb_snapshot`**
`id`, `tmdb_id`, `media_type`, `title`, `popularity`, `vote_average`, `vote_count`, `rank`, `snapshot_date`

### Canonical layer — written by Symfony, after enrichment (#9)

**`canonical.book`** — `id`, `isbn13`, `title`, `author`, `first_publish_year`, `open_library_id`, `subjects`
**`canonical.film`** — `id`, `tmdb_id`, `title`, `release_date`, `runtime`, `genres`, `vote_average`
**`canonical.adaptation`** — `id`, `book_id`, `film_id`, `match_confidence`, `matched_on` *(output of entity matching)*

### MART layer — written by Python

**`mart.bestseller_longevity`** — `book_id`, `first_seen`, `last_seen`, `peak_rank`, `total_weeks`
**`mart.genre_trend`** — `period`, `genre`, `avg_popularity`, `count`
**`mart.adaptation_comparison`** — `adaptation_id`, `years_to_adapt`, `film_vote_average`, `book_longevity_signal`, `delta`

---

## 6. Symfony / Python split

### Symfony — the deep end of the stack

- Ingestion services via **HTTP Client**, one per source, with error handling and
  rate limiting (#5, #6, #7)
- **Scheduler** for cadence, **Messenger** for async work and workers (#8)
- **Doctrine** for canonical entities and migrations (#9)
- Open Library enrichment (ISBN → metadata) (#7)
- **Rule-based adaptation matching** — title + author + year. Business logic, and
  it belongs in PHP (#10)
- The **REST API** serving the dashboard (#16)

### Python — introduced for what it genuinely does better

- Reading RAW via **pandas** (and **SQLAlchemy** / `psycopg`)
- **Temporal aggregations**: bestseller longevity, genre trends per period
  (#12, #13)
- **Fuzzy matching** (`rapidfuzz`) to recover titles that do not match exactly —
  *this is where Python truly earns its seat* (#11)
- **Statistics**: correlation between book longevity and adaptation rating (#14)
- *(Optional)* **recommendation** — "if you liked this adapted book, try…" via
  subject similarity (`scikit-learn`) (#15)

> ⚠️ **Résumé-driven-architecture guardrail:** only put Python where it actually
> pays. If the work is basic SQL aggregation, forcing Python through it is
> architecture dictated by a CV — a flaw, not a signal. Fuzzy matching and the
> recommender justify its presence on their own.

`scikit-learn` is declared as an **optional** dependency group (`ml`) rather than
a default install, so the runtime image stays small until #15 earns it.

---

## 7. Delivery phases

Every phase must end on **something deployed and working**. One project that runs
beats three half-built ones.

### Phase 0 — Setup ✅ done (#1–#4)
Repo, `docker compose` (FrankenPHP, Postgres, Python worker), env vars, TMDB +
NYT keys, Symfony skeleton, health endpoint, Next.js scaffold.
**Still outstanding: deploy the skeleton** (Fly.io, Railway, or a small VPS). No
hosting decision has been made yet.

### Phase 1 — Ingestion MVP 🔑 (#5, #6, #7, #8)
HTTP services for NYT + TMDB, a command that fetches and stores a snapshot,
Scheduler config (TMDB daily, NYT weekly). **Deploy and start the pipeline here.**

> **Critical insight:** the time series only exists if the pipeline *has been
> running for a while*. Every week it is not deployed is a week of data lost for
> good. So this phase ships before the analyses — time passing works for you.

### Phase 2 — Enrichment + matching (#9, #10)
Open Library enrichment, canonical entities, rule-based book ↔ adaptation
matching. `canonical.adaptation` populated.

### Phase 3 — Python analysis layer (#11, #12, #13, #14, #15)
Read RAW with pandas, compute longevity, genre trends and adaptation
comparisons, write the MART tables. Add **fuzzy matching** to improve the links.
Optionally, a first recommender.

### Phase 4 — Dashboard (#16, #17, #18)
API endpoints serving the marts; front-end visualizations: time series,
"longest-charting books", "book rating vs adaptation rating", genre evolution.

### Phase 5 — Polish & signal (ongoing, #19, #20)
README with an architecture diagram and the *whys*; a short blog post on one
technical decision (ideally the entity matching); tests on ingestion and
matching; GitHub Actions CI; basic logging and metrics.

---

## 8. Stack, as actually built

- **Symfony 8.1**, **PHP 8.5**, served by **FrankenPHP** (not php-fpm + nginx)
- **Doctrine ORM** + **PostgreSQL 16**
- **Symfony HTTP Client, Messenger, Scheduler**
- **Python 3.12**, dependencies managed with **uv**, scheduled by **supercronic**:
  `pandas`, `rapidfuzz`, `SQLAlchemy` / `psycopg`; `scikit-learn` optional
- **Front: Next.js 16 (App Router) + TypeScript + Recharts + Tailwind**, yarn on
  the host. *The original plan offered "Twig + Chart.js" or "a small Vue/React
  SPA"; a Next.js app was chosen for the stronger fullstack signal.*
- **Docker** + `docker compose`
- **Deployment:** undecided — Fly.io / Railway / VPS all still on the table
- **CI:** GitHub Actions (phpunit + pytest + shellcheck + linters)

Tooling lives in containers, not on the host: composer runs in the `php`
container, pytest and ruff in `analytics`. `web/` is the exception — yarn runs on
the host. `make` with no arguments lists every target.

---

## 9. The "whys" worth capturing in the README

This is **the differentiator**. An AI generates the code; it does not generate the
reasoning. Worth documenting:

1. Why a shared-database seam rather than an HTTP API between services.
2. Why layers are schemas with owners, rather than table-name prefixes.
3. Why Python only for the analysis layer, and not everywhere.
4. Why Messenger/Scheduler for orchestration.
5. The entity-matching approach and how confidence is handled
   (`match_confidence`).
6. The RAW → canonical → MART modelling and what it buys.

---

## 10. Scope guardrails

- **Vertical slice first:** one NYT list + TMDB trending → storage → a single
  working chart, end to end, deployed. *Then* widen.
- Do not build every analysis at once. One solid analysis beats five sketched.
- The time series needs calendar time: **deploy ingestion in week 1**, even if the
  dashboard arrives weeks later.
- One flagship project done properly beats several rushed ones.
- Scaffolding is labelled. `raw.ingest_heartbeat` and the `hello` job exist only
  to prove the seam works; they are deleted in #5/#6. Do not build on them.

---

## The story the project tells

> "I know my Symfony stack in depth — ingestion, orchestration, API, front — I
> know the data engineering patterns (RAW/canonical/MART, scheduled jobs, entity
> matching), and I can reach toward data with the right tool: Python where it
> genuinely pays. All of it deployed, tested, and documented down to the
> decisions."
