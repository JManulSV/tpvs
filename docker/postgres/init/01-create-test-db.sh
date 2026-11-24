#!/bin/sh
# Creates the tpvs_test database used by the backend test suite.
# PostgreSQL runs this only when the data volume is empty (first start).
# Idempotent: does nothing if tpvs_test already exists.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<'SQL'
SELECT 'CREATE DATABASE tpvs_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'tpvs_test')\gexec
SQL
