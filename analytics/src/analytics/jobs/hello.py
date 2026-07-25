"""Hello-world job: read a RAW table, write a MART row.

Exists to prove the seam described in db/README.md end to end — that this
container can reach the database as the `analytics` role, read a layer Symfony
owns, and write its own. It is scaffolding for issue #3; the real jobs arrive
with #12-#14.
"""

from __future__ import annotations

import logging
import sys
from datetime import UTC, datetime

import pandas as pd
from sqlalchemy import text

from analytics.db import connection, engine, job_lock

logger = logging.getLogger("analytics.jobs.hello")

# The analytics role owns `mart`, so this service owns mart DDL (db/README.md).
# Real jobs will want a migration tool; for one scaffold table, CREATE IF NOT
# EXISTS is honest and cheap.
CREATE_MART_TABLE = """
CREATE TABLE IF NOT EXISTS mart.hello_world (
    id            SERIAL PRIMARY KEY,
    computed_at   TIMESTAMPTZ NOT NULL,
    raw_row_count INTEGER     NOT NULL
)
"""


def run() -> int:
    """Return the number of RAW rows observed."""
    with connection() as conn:
        conn.execute(text(CREATE_MART_TABLE))

    # pandas is overkill for a row count, but it is the read path every real job
    # will use, so exercise it here rather than discovering problems in #12.
    frame = pd.read_sql("SELECT id, source, observed_at FROM raw.ingest_heartbeat", engine())
    row_count = len(frame)

    with connection() as conn:
        conn.execute(
            text(
                "INSERT INTO mart.hello_world (computed_at, raw_row_count) "
                "VALUES (:computed_at, :row_count)"
            ),
            {"computed_at": datetime.now(UTC), "row_count": row_count},
        )

    return row_count


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
        stream=sys.stdout,
    )

    with job_lock("hello") as acquired:
        if not acquired:
            # Not an error: the previous run is still going. Skip this tick.
            logger.warning("hello: previous run still holds the lock, skipping this tick")
            return 0

        row_count = run()
        logger.info("hello: read %d raw row(s), wrote 1 mart row", row_count)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
