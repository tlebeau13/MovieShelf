"""Database access for the analytics service.

Everything here connects as the `analytics` role: read-only on `raw` and
`canonical`, read-write on `mart`. That boundary is enforced by PostgreSQL
grants (see db/init/02-schemas.sql), not by anything in this module — the point
is that a bug here fails loudly at the database instead of corrupting a layer we
do not own.
"""

from __future__ import annotations

import os
from collections.abc import Iterator
from contextlib import contextmanager
from functools import cache

from sqlalchemy import Engine, create_engine, text
from sqlalchemy.engine import Connection


class ConfigError(RuntimeError):
    """Raised when required configuration is missing."""


def database_url() -> str:
    url = os.environ.get("DATABASE_URL")
    if not url:
        raise ConfigError(
            "DATABASE_URL is not set. Under docker compose it is injected by "
            "compose.yaml; locally, export the analytics DSN yourself."
        )
    # SQLAlchemy needs the driver spelled out, but the DSN is also consumed by
    # plain psql/psycopg elsewhere, so accept the bare form and normalise it.
    if url.startswith("postgresql://"):
        url = url.replace("postgresql://", "postgresql+psycopg://", 1)
    return url


@cache
def engine() -> Engine:
    # pool_pre_ping: cron jobs are short-lived, but the db container can restart
    # between runs and a stale pooled socket would surface as a confusing error.
    return create_engine(database_url(), pool_pre_ping=True, future=True)


@contextmanager
def connection() -> Iterator[Connection]:
    with engine().begin() as conn:
        yield conn


# Arbitrary but fixed: advisory lock keys are a global namespace per database,
# so every job that must not overlap picks its own constant here.
LOCK_KEYS: dict[str, int] = {
    # The `hello` scaffolding held 4_100_001 until #6 deleted it; the real jobs
    # claim their own keys here as they land (#12-#14).
}


@contextmanager
def job_lock(name: str) -> Iterator[bool]:
    """Session-level advisory lock, so two runs of a job never overlap.

    supercronic fires on schedule regardless of whether the previous run
    finished. A job that overruns its interval would otherwise get a second
    process writing the same mart tables concurrently. Yields False when the
    lock is already held, and the caller is expected to skip rather than wait —
    the next tick will pick it up.
    """
    key = LOCK_KEYS[name]
    # A dedicated connection: the lock must outlive any transaction the job runs.
    with engine().connect() as conn:
        acquired = conn.execute(text("SELECT pg_try_advisory_lock(:key)"), {"key": key}).scalar()
        try:
            yield bool(acquired)
        finally:
            if acquired:
                conn.execute(text("SELECT pg_advisory_unlock(:key)"), {"key": key})
