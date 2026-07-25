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
