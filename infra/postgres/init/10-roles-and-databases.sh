#!/usr/bin/env bash
# Runs once when the PostgreSQL volume is empty (docker-entrypoint-initdb.d).
# Roles (ADR-0005, docs/08-database/tenancy.md):
#   helpdesk_owner  owns the databases and every table; used for migrations and provisioning only
#   helpdesk_app    runtime role: DML only, never owner, NOBYPASSRLS, so row-level security applies
#   helpdesk_backup read-only role with BYPASSRLS for pg_dump
set -euo pipefail

# Passwords are exported by infra/postgres/entrypoint.sh, which reads the secrets as root.
owner_pw="${HELPDESK_OWNER_PASSWORD:?missing secret db_owner_password}"
app_pw="${HELPDESK_APP_PASSWORD:?missing secret db_app_password}"
backup_pw="${HELPDESK_BACKUP_PASSWORD:?missing secret db_backup_password}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v owner_pw="$owner_pw" -v app_pw="$app_pw" -v backup_pw="$backup_pw" <<'SQL'
-- Idempotent, because CI runs this script against a fresh service container on every job.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'helpdesk_owner') THEN
        CREATE ROLE helpdesk_owner LOGIN NOSUPERUSER NOCREATEROLE NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'helpdesk_app') THEN
        CREATE ROLE helpdesk_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'helpdesk_backup') THEN
        CREATE ROLE helpdesk_backup LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
    END IF;
END $$;
ALTER ROLE helpdesk_owner PASSWORD :'owner_pw';
ALTER ROLE helpdesk_app PASSWORD :'app_pw';
ALTER ROLE helpdesk_backup PASSWORD :'backup_pw';
SQL

# helpdesk_{a,b,c}_test let parallel workers run the backend suite at the same time
# (TEST_DATABASE=helpdesk_a_test, see backend/tests/TestCase.php).
for db in helpdesk helpdesk_test helpdesk_a_test helpdesk_b_test helpdesk_c_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname = '${db}'" | grep -q 1 ||
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE ${db} OWNER helpdesk_owner"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<SQL
ALTER SCHEMA public OWNER TO helpdesk_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;
GRANT CONNECT, TEMPORARY ON DATABASE ${db} TO helpdesk_app, helpdesk_backup;
GRANT USAGE ON SCHEMA public TO helpdesk_app, helpdesk_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE helpdesk_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO helpdesk_app;
ALTER DEFAULT PRIVILEGES FOR ROLE helpdesk_owner IN SCHEMA public
  GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO helpdesk_app;
ALTER DEFAULT PRIVILEGES FOR ROLE helpdesk_owner IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO helpdesk_app;
ALTER DEFAULT PRIVILEGES FOR ROLE helpdesk_owner IN SCHEMA public
  GRANT SELECT ON TABLES TO helpdesk_backup;
SQL
done
