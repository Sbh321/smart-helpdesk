#!/usr/bin/env bash
# Host backup safety net (docs/09-infrastructure/backups.md §Host safety net). Installed by the Ansible
# `backup` role as /usr/local/bin/helpdesk-backup and run by helpdesk-backup.timer (daily 02:30) and
# before every deploy (label pre-deploy). It needs only Docker and the Compose project, not Laravel.
#
#   helpdesk-backup [label]            label: daily (default) | pre-deploy | manual | ...
#   HELPDESK_DIR     Compose project directory with .env (default /opt/smart-helpdesk)
#   BACKUP_DIR       target directory (default $HELPDESK_DIR/backups)
#   RETENTION_DAYS   delete daily dumps older than this (default 7)
#   BACKUP_FILES     1 = also archive the bundled RustFS data (storage profile), default 1 when rustfs runs
#
# Output: helpdesk-<UTC timestamp>-<label>.dump (pg_dump custom format, compressed, with --create so the
# database, its owner and grants are recreated) and, with files, objects-<timestamp>-<label>.tar.gz.
# The dump runs as the `postgres` superuser over the container's local socket: the runtime role is
# subject to row-level security, and pg_dump refuses tables whose policies would hide rows.
# MVP-SHORTCUT: dumps stay on the host unless the operator fetches them (backup.yml -e fetch_backup=true);
# V1: V1-PL-16 (encrypted off-site archives with spatie/laravel-backup).
set -euo pipefail

label="${1:-daily}"
dir="${HELPDESK_DIR:-/opt/smart-helpdesk}"
out="${BACKUP_DIR:-$dir/backups}"
keep="${RETENTION_DAYS:-7}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
cd "$dir"   # .env there sets COMPOSE_FILE, COMPOSE_PROJECT_NAME and COMPOSE_PROFILES
compose=(docker compose)

umask 077
mkdir -p "$out"
trap 'rm -f "$out"/*.part' EXIT

dump="$out/helpdesk-$stamp-$label.dump"
"${compose[@]}" exec -T postgres pg_dump -U postgres -d helpdesk --create --format=custom --compress=6 > "$dump.part"
# Integrity: the archive must list its table of contents, and it must contain table data.
"${compose[@]}" exec -T postgres pg_restore --list < "$dump.part" | grep -q 'TABLE DATA public tenants'
mv "$dump.part" "$dump"
echo "database: $dump ($(du -h "$dump" | cut -f1))"

if [[ ${BACKUP_FILES:-auto} != 0 ]] && "${compose[@]}" ps --status running --services 2>/dev/null | grep -qx rustfs; then
  objects="$out/objects-$stamp-$label.tar.gz"
  "${compose[@]}" exec -T rustfs tar -C /data -czf - . > "$objects.part"
  gzip -t "$objects.part"
  mv "$objects.part" "$objects"
  echo "objects:  $objects ($(du -h "$objects" | cut -f1))"
fi

# Retention applies to scheduled backups only; pre-deploy and manual ones are kept until removed.
find "$out" -maxdepth 1 -type f \( -name '*-daily.dump' -o -name '*-daily.tar.gz' \) -mtime +"$keep" -print -delete
