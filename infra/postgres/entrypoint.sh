#!/usr/bin/env bash
# Wrapper around the official entrypoint. It starts as root, so it can read the
# bind-mounted secrets while the host files stay mode 600. The official entrypoint
# then drops to the postgres user before running docker-entrypoint-initdb.d, which
# reads the role passwords from these variables. They only matter on first init.
set -euo pipefail

for name in db_owner_password db_app_password db_backup_password; do
  var="HELPDESK_$(echo "${name#db_}" | tr '[:lower:]' '[:upper:]')"
  if [ -r "/run/secrets/$name" ]; then
    export "$var=$(tr -d '\n' < "/run/secrets/$name")"
  fi
done

exec docker-entrypoint.sh "$@"
