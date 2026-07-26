"""The write boundary from db/README.md, asserted rather than documented.

This is the `analytics` half of the matrix; api/tests/Integration/WriteBoundaryTest.php
owns the `symfony` half. Both run through their service's own connection layer on
purpose: the claim under test is not "the grants are right" but "the connection
this service actually opens is the constrained one".

Cross-role writes need a table the other role owns, which no single connection
can set up. db/verify-contract.sh drives both roles and covers those.
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


def default_privileges_for(schema: str) -> list[str]:
    """Privileges analytics will hold on tables created in `schema` from now on."""
    sql = text("""
        SELECT DISTINCT a.privilege_type
        FROM pg_default_acl d
        JOIN pg_namespace n ON n.oid = d.defaclnamespace
        CROSS JOIN aclexplode(d.defaclacl) a
        WHERE n.nspname = :schema
          AND d.defaclobjtype = 'r'
          AND a.grantee = current_user::regrole
        ORDER BY 1
    """)
    with connection() as conn:
        return list(conn.execute(sql, {"schema": schema}).scalars())


def test_connects_as_the_analytics_role():
    """Everything below is meaningless if the DSN drifted to the superuser."""
    with connection() as conn:
        assert conn.execute(text("SELECT current_user")).scalar() == "analytics"


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


@pytest.mark.parametrize("schema", ["raw", "canonical"])
def test_future_tables_are_readable_but_not_writable(schema):
    """The promise ALTER DEFAULT PRIVILEGES makes, for every table not written yet.

    Anything beyond SELECT here would be a silent write path into a layer we do
    not own, opening on its own the next time Symfony runs a migration.
    """
    assert default_privileges_for(schema) == ["SELECT"]


def test_has_no_create_privilege_on_symfony_schemas():
    with connection() as conn:
        for schema in ("raw", "canonical"):
            assert not conn.execute(
                text("SELECT has_schema_privilege(current_user, :s, 'CREATE')"), {"s": schema}
            ).scalar(), f"analytics can add tables to {schema}"
            assert conn.execute(
                text("SELECT has_schema_privilege(current_user, :s, 'USAGE')"), {"s": schema}
            ).scalar(), f"analytics cannot see into {schema}, so reads are broken"


def test_can_write_own_mart_schema():
    """The boundary must not be so tight that analytics cannot do its job."""
    with connection() as conn:
        conn.execute(text("CREATE TABLE IF NOT EXISTS mart.permission_probe (id INT)"))
        conn.execute(text("INSERT INTO mart.permission_probe (id) VALUES (1)"))
        conn.execute(text("DROP TABLE mart.permission_probe"))
