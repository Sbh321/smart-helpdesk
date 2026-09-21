#!/usr/bin/env bash
# Restore a database dump (and optionally the object archive) written by helpdesk-backup
# (docs/09-infrastructure/disaster-recovery.md). Installed as /usr/local/bin/helpdesk-restore by the
# Ansible `backup` role; `restore.yml` calls it. Destructive: the current database is dropped.
#
#   helpdesk-restore <helpdesk-….dump> [objects-….tar.gz]
#   HELPDESK_DIR  Compose project directory with .env (default /opt/smart-helpdesk)
#   RESTORE_YES   1 = do not ask for confirmation
#
# Steps: stop the application containers → drop and recreate `helpdesk` from the dump (as the postgres
# superuser, so owners, grants, RLS policies and triggers come back exactly) → restore objects →
# migrate (owner role) for migrations newer than the dump → clear caches → start → wait for health.
# The database roles are cluster-level and are not in the dump: they already exist on any host
# initialised from the same secrets/ (infra/postgres/init).
set -euo pipefail

dump="${1:?usage: helpdesk-restore <dump> [objects.tar.gz]}"
objects="${2:-}"
dir="${HELPDESK_DIR:-/opt/smart-helpdesk}"
cd "$dir"   # .env there sets COMPOSE_FILE, COMPOSE_PROJECT_NAME and COMPOSE_PROFILES
compose=(docker compose)
app_services=(proxy app horizon scheduler reverb)

[[ -r $dump ]] || { echo "cannot read $dump" >&2; exit 1; }
[[ -z $objects || -r $objects ]] || { echo "cannot read $objects" >&2; exit 1; }
if [[ ${RESTORE_YES:-0} != 1 ]]; then
  read -r -p "Drop database helpdesk in $dir and restore $(basename "$dump")? [y/N] " answer
  [[ $answer == y || $answer == Y ]] || exit 1
fi

"${compose[@]}" exec -T postgres pg_restore --list < "$dump" > /dev/null   # readable archive before dropping anything

echo "stopping application containers"
"${compose[@]}" stop "${app_services[@]}" 2>/dev/null || true

echo "recreating database helpdesk from $(basename "$dump")"
"${compose[@]}" exec -T postgres dropdb -U postgres --if-exists --force helpdesk
"${compose[@]}" exec -T postgres pg_restore -U postgres --create --exit-on-error -d postgres < "$dump"

if [[ -n $objects ]]; then
  echo "restoring objects from $(basename "$objects")"
  "${compose[@]}" exec -T rustfs sh -c 'find /data -mindepth 1 -maxdepth 1 -exec rm -rf {} + && tar -C /data -xzf -' < "$objects"
  "${compose[@]}" restart rustfs
fi

echo "applying newer migrations (owner role)"
"${compose[@]}" run --rm --no-deps migrate
"${compose[@]}" run --rm --no-deps migrate php artisan cache:clear

echo "starting application containers"
"${compose[@]}" up -d --wait
"${compose[@]}" exec -T app php artisan storage:ensure-bucket --check || true
echo "restore finished; run infra/scripts/smoke.sh against the host"
