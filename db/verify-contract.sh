#!/usr/bin/env bash
# Proves the RAW/canonical/MART write boundary end to end, by driving both roles.
#
# The per-service suites (api/tests/Integration/WriteBoundaryTest.php,
# analytics/tests/test_permissions.py) each hold one connection, so neither can
# test the case that matters most: writing into a table the *other* role owns.
# This script can, because it opens both. Run it against a live stack:
#
#   make db-verify
#
# Everything it creates is dropped again, including on failure.
set -euo pipefail

DB=${POSTGRES_DB:-movieshelf}
COMPOSE=${COMPOSE:-docker compose}

# VERBOSITY=verbose makes psql prefix errors with their SQLSTATE, so denials are
# matched on the code (42501) instead of an English message that varies by locale.
psql_as() {
  local role=$1 sql=$2
  $COMPOSE exec -T db psql -v ON_ERROR_STOP=1 -v VERBOSITY=verbose \
    -U "$role" -d "$DB" -tAc "$sql"
}

fail() {
  echo "::error::$*" >&2
  echo "contract violated: $*" >&2
  exit 1
}

# Allowed: the statement must succeed.
allow() {
  local role=$1 sql=$2
  psql_as "$role" "$sql" >/dev/null \
    || fail "$role should be able to run, but could not: $sql"
}

# Denied: the statement must fail, and specifically with insufficient_privilege.
# A statement that fails for some other reason (typo, missing table) would
# otherwise look like the boundary holding when it is really the test rotting.
deny() {
  local role=$1 sql=$2 output
  if output=$(psql_as "$role" "$sql" 2>&1); then
    fail "$role was allowed to run: $sql"
  fi
  grep -q '42501' <<<"$output" \
    || fail "$role failed on '$sql', but not with a permission denial: $output"
}

cleanup() {
  psql_as symfony   'DROP TABLE IF EXISTS raw.contract_probe'  >/dev/null 2>&1 || true
  psql_as analytics 'DROP TABLE IF EXISTS mart.contract_probe' >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "→ schemas exist and are owned by their writer"
# Read as `symfony`, not the superuser: pg_namespace is world-readable, and
# nothing in this repo should need superuser credentials to check the contract.
for pair in raw:symfony canonical:symfony mart:analytics; do
  schema=${pair%%:*} expected=${pair##*:}
  owner=$(psql_as symfony "SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = '$schema'")
  [[ $owner == "$expected" ]] || fail "schema $schema is owned by '$owner', expected '$expected'"
done

echo "→ each role can write its own layer"
allow symfony   'CREATE TABLE raw.contract_probe (id INT)'
allow symfony   'INSERT INTO raw.contract_probe (id) VALUES (1)'
allow analytics 'CREATE TABLE mart.contract_probe (id INT)'
allow analytics 'INSERT INTO mart.contract_probe (id) VALUES (1)'

echo "→ each role can read the other's layer"
allow analytics 'SELECT count(*) FROM raw.contract_probe'
allow symfony   'SELECT count(*) FROM mart.contract_probe'

echo "→ neither role can write the other's layer"
# The tables above exist and are readable, so a denial here is about the write
# privilege alone — this is the case the single-connection suites cannot reach.
deny symfony   'INSERT INTO mart.contract_probe (id) VALUES (99)'
deny symfony   'UPDATE mart.contract_probe SET id = 99'
deny symfony   'DELETE FROM mart.contract_probe'
deny symfony   'CREATE TABLE mart.intruder (id INT)'
deny symfony   'DROP TABLE mart.contract_probe'

deny analytics 'INSERT INTO raw.contract_probe (id) VALUES (99)'
deny analytics 'UPDATE raw.contract_probe SET id = 99'
deny analytics 'DELETE FROM raw.contract_probe'
deny analytics 'CREATE TABLE raw.intruder (id INT)'
deny analytics 'CREATE TABLE canonical.intruder (id INT)'
deny analytics 'DROP TABLE raw.contract_probe'

echo "→ neither role can reach the other's layer by escalating"
deny symfony   'ALTER SCHEMA mart OWNER TO symfony'
deny analytics 'ALTER SCHEMA raw OWNER TO analytics'

# Self-GRANT is checked by effect, not by exit code: PostgreSQL treats a GRANT on
# tables you do not own as a no-op with a WARNING, not an error, so the statement
# "succeeds" while granting nothing. What matters is that the write still fails.
psql_as symfony   'GRANT INSERT ON ALL TABLES IN SCHEMA mart TO symfony'  >/dev/null 2>&1 || true
psql_as analytics 'GRANT INSERT ON ALL TABLES IN SCHEMA raw  TO analytics' >/dev/null 2>&1 || true
deny symfony   'INSERT INTO mart.contract_probe (id) VALUES (99)'
deny analytics 'INSERT INTO raw.contract_probe (id) VALUES (99)'

echo "✅ contract holds"
