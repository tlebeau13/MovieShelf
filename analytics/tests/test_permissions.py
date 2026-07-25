"""The write boundary from db/README.md, asserted rather than documented.

Scope is deliberately one-directional: this issue (#3) only owes proof that the
analytics role cannot write RAW or canonical. The symfony-cannot-write-MART half
of the matrix, and wiring any of it into CI, belong to #19.
"""

from __future__ import annotations

import os

import pytest
from sqlalchemy import text
from sqlalchemy.exc import ProgrammingError

from analytics.db import connection

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(
        not os.environ.get("DATABASE_URL"),
        reason="needs a live database; run inside the analytics container",
    ),
]

# SQLSTATE 42501. Matching on the code rather than the message keeps this stable
# across PostgreSQL versions and locales.
INSUFFICIENT_PRIVILEGE = "42501"


def assert_denied(sql: str) -> None:
    with pytest.raises(ProgrammingError) as excinfo:
        with connection() as conn:
            conn.execute(text(sql))
    assert excinfo.value.orig.sqlstate == INSUFFICIENT_PRIVILEGE, (
        f"expected a permission denial, got: {excinfo.value.orig}"
    )


def test_can_read_raw():
    """Read access is the other half of the contract — a denial here is also a bug."""
    with connection() as conn:
        assert conn.execute(text("SELECT count(*) FROM raw.ingest_heartbeat")).scalar() >= 0


def test_cannot_insert_into_raw():
    assert_denied("INSERT INTO raw.ingest_heartbeat (source) VALUES ('intruder')")


def test_cannot_update_raw():
    assert_denied("UPDATE raw.ingest_heartbeat SET source = 'intruder'")


def test_cannot_delete_from_raw():
    assert_denied("DELETE FROM raw.ingest_heartbeat")


def test_cannot_create_table_in_raw():
    assert_denied("CREATE TABLE raw.intruder (id INT)")


def test_cannot_create_table_in_canonical():
    """canonical has no tables yet (#9), so CREATE is the only reachable write."""
    assert_denied("CREATE TABLE canonical.intruder (id INT)")


def test_can_write_own_mart_schema():
    """The boundary must not be so tight that analytics cannot do its job."""
    with connection() as conn:
        conn.execute(text("CREATE TABLE IF NOT EXISTS mart.permission_probe (id INT)"))
        conn.execute(text("INSERT INTO mart.permission_probe (id) VALUES (1)"))
        conn.execute(text("DROP TABLE mart.permission_probe"))
