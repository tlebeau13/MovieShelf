# MovieShelf — Film & Book Trend Tracker

**Portfolio project: a hybrid Symfony + Python data pipeline.**

Ingests book bestseller lists (New York Times) and film metadata (TMDB), models
how books chart over time and which genres publishing and film pursue, and serves
an analytics dashboard. Symfony orchestrates and serves; Python analyses.

> A pipeline that builds a base of cultural trends over time and analyses them,
> architected polyglot: Symfony for ingestion and serving, Python for analysis —
> each tool on its own ground.

## What it is trying to achieve

The project has no users. It has a reader — someone assessing the work — so every
part of it exists to make a claim that can be checked in a few minutes.

| Claim | Where it shows up |
|---|---|
| Architectural judgement | The write boundary between the two services is enforced by Postgres grants, not by convention. Verifiable in one command: `make db-verify` |
| Data engineering / ETL | Scheduled ingestion, transformation, a real RAW → canonical → MART decomposition |
| Statistical analysis | Trajectory clustering (#33) and genre lead/lag (#36) — work that justifies Python rather than more SQL |
| Orchestration | Symfony Scheduler for cadence, Messenger for async workers |
| Fullstack | REST API plus a visualization dashboard |
| Production literacy | Docker, per-component CI, deployment, observability, tests |

The reasoning is the deliverable as much as the code. Anything generated can
produce a working pipeline; what it cannot produce is why this pipeline is shaped
this way. Those decisions live in [ARCHITECTURE.md](ARCHITECTURE.md).

## Status

The infrastructure is complete and carries no data yet. Three Postgres schemas with
enforced ownership, four components under `docker compose`, per-component CI, and a
typed seam between the dashboard and the API — but no external API has been called
yet, and `canonical` and `mart` are empty. Phase 1 changes that.

## Rescoped 2026-07-26

Book ↔ adaptation matching was originally the centrepiece. It is now phase 3.

Matching is the most expensive claim in the project and the one most exposed to a
hard ceiling. There is no shared identifier between a book and its film, so linking
them is string comparison — which handles cosmetic drift ("The Silver Linings
Playbook" → "Silver Linings Playbook") but cannot touch wholesale retitling ("Do
Androids Dream of Electric Sheep?" → "Blade Runner"). Those adaptations are
unreachable without per-title "based on the novel by" metadata that neither source
publishes reliably.

The analyses that need **no** linking are cheaper *and* better justification for
Python being here at all:

- **Trajectory clustering** (#33) — do bestsellers follow archetypal shapes?
  Spike-and-crash, slow climb, plateau, re-entry. That is k-means or DTW over
  curves, not a SQL query.
- **Genre lead/lag** (#36) — do publishing and film chase the same genres, and
  which moves first? Two aggregate series and a cross-correlation, with no
  per-title link anywhere in the computation.

So phases 1 and 2 ship a working product from one and then two sources, and *also*
ingest everything phase 3 needs. Matching becomes purely additive rather than a
precondition for seeing anything at all. No architecture changed: same
RAW → canonical → MART flow, same grants, same four components.

## Delivery phases

Each phase ends on a **demoable dashboard**, from one more source than the last.
That ordering is deliberate: what kills a portfolio project is not wrong
architecture, it is abandonment at 70% with nothing to show. The GitHub milestones
mirror these phases exactly — `gh issue list` is the live version of this table.

| Phase | Ships | Sources |
|---|---|---|
| **0 — Foundation** ✅ | Compose stack, three schemas, two roles, per-component CI | — |
| **1 — Books** 🔑 | Rank-over-time chart, longevity, trajectory archetypes | NYT |
| **2 — Both media** | Film genre trends, book subject trends, lead/lag view | + TMDB, Open Library |
| **3 — Adaptations** | Book ↔ film linking, longevity-vs-rating scatter | — |
| **4 — Polish & deploy** | Hosting, observability, the public README | — |

**The dashboards have real history from day one.** #28 established that NYT serves
past bestseller lists by date and TMDB genre trends can be bucketed on release date,
so nothing waits on months of cron runs. The cost is a quota: NYT allows 1,000
requests/day, which is about one list-decade per day, so the backfill is a
resumable multi-day job rather than a single run.

Only TMDB `popularity` is genuinely point-in-time and accumulates forward. Series
derived from record dates and series derived from observation dates answer different
questions, and the dashboard must not chart them as one.

## Scope guardrails

- **Vertical slice first:** one NYT list → storage → a single working chart, end to
  end, deployed. *Then* widen.
- One solid analysis beats five sketched ones.
- Only put Python where it actually pays. If the work is a `GROUP BY`, forcing
  Python through it is architecture dictated by a CV — a flaw, not a signal.
- Scaffolding is labelled: `raw.ingest_heartbeat`, `mart.hello_world` and the
  `hello` job exist only to prove the seam works, and are deleted in #5/#6. Do not
  build on them.

## Getting started

```bash
cp .env.example .env   # throwaway local passwords
make up                # start every service
make help              # every target, per component
make check             # the same lint and test set CI runs
```

Tooling lives in containers, not on the host: composer runs in the `php` container,
pytest and ruff in `analytics`. `web/` is the exception — yarn runs on the host.

## Where the detail lives

- [ARCHITECTURE.md](ARCHITECTURE.md) — the architectural decisions, and why
- [db/README.md](db/README.md) — the contract between the services; the important one
- [api/README.md](api/README.md), [analytics/README.md](analytics/README.md),
  [web/README.md](web/README.md) — per-component detail
- `gh issue list` — the work, phase by phase
