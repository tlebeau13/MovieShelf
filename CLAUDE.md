# MovieShelf

Bestseller lists (NYT) crossed with their screen adaptations (TMDB). Four
components, one Postgres database.

| Dir          | Stack                          | Owns                                    |
|--------------|--------------------------------|-----------------------------------------|
| `api/`       | Symfony 8.1, PHP 8.5, FrankenPHP | Ingestion, canonical entities, REST API |
| `analytics/` | Python 3.12, uv, supercronic   | Recomputation: reads RAW, writes MART   |
| `web/`       | Next.js 16, TypeScript, Recharts | Dashboard                             |
| `db/`        | Postgres 16                    | The contract between the services       |

Work is issue-driven — see `gh issue list`. Each component has its own README
with more detail; `db/README.md` is the important one.

## Hard rules

**Symfony and Python talk through Postgres, not HTTP.** The pipeline is batch.
Never add an HTTP call between `api/` and `analytics/`.

**Write boundaries are enforced by Postgres grants, not convention:**

- `symfony` role — writes `raw` + `canonical`, reads `mart`
- `analytics` role — writes `mart`, reads `raw` + `canonical`

Never connect as the superuser from application code. Never write outside your
layer; the database will reject it, and that rejection is the design working.

**`web/` reaches data only through `api/` over HTTP.** No database driver, no
connection string, anywhere in that codebase.

## Commands

`make` with no arguments lists everything. Prefer it over raw commands — the
targets encode which container and which role a command needs.

Tooling lives in containers, not on the host: composer runs in the `php`
container, pytest and ruff in `analytics`. The exception is `web/`, where yarn
runs on the host.

## Conventions

**Comments — why, not what.** Assume the reader knows the language.

- Do comment: non-obvious constraints, traps that cost debugging time, decisions
  where the obvious alternative was rejected and it isn't clear why.
- Don't comment: what the next line does, section banner separators, restating a
  well-named function.
- One sentence beats a paragraph. Module docstrings: 1–2 lines.

**Commits:** conventional, scoped by component — `feat(analytics): …`,
`fix(api): …`. Don't commit unless asked.

**Dependencies:** each component pins its own (`composer.lock`, `uv.lock`,
`yarn.lock`). Adding one is a decision worth raising, not a detail.

**Scaffolding is labelled.** `raw.ingest_heartbeat` and the `hello` job exist
only to prove the seam works; they get deleted in #5/#6. Don't build on them.
