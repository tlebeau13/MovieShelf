#!/bin/bash
# Runs once, on first container boot (empty data volume), as the POSTGRES superuser.
#
# Why a .sh and not a .sql: role passwords come from the environment, and a plain
# .sql init file cannot read env vars. We pass them to psql as *variables* (--set),
# then reference them with :'name', which safely quotes the value as a string
# literal — no shell interpolation into the SQL text.
set -euo pipefail

psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "$POSTGRES_DB" \
     --set=symfony_pw="$SYMFONY_DB_PASSWORD" \
     --set=analytics_pw="$ANALYTICS_DB_PASSWORD" <<'SQL'
  CREATE ROLE symfony   LOGIN PASSWORD :'symfony_pw';
  CREATE ROLE analytics LOGIN PASSWORD :'analytics_pw';
SQL
