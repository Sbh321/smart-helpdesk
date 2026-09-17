# Backups

Rule: a backup that lives on the same disk as the database is not a backup, and an untested restore is not a backup. Targets: RPO 24 h, RTO 4 h ([disaster-recovery.md](disaster-recovery.md)).

## What is backed up

| Asset | Where it lives | Mechanism | Frequency |
|---|---|---|---|
| PostgreSQL (all tenants, control plane, Passport, audit) | `pg-data` volume | `spatie/laravel-backup` (`pg_dump` inside `app`) → `backups` disk; host `pg_dump` timer → `/opt/smart-helpdesk/backups` | daily 01:30 (spatie), 02:30 (host) |
| Attachments, logos, exports | S3 bucket `helpdesk` | bucket-to-bucket sync job (`storage:sync-backup`, `aws s3 sync`-style listing + copy of new keys) to the `backups` bucket/prefix; object versioning where the provider supports it | daily 03:00 |
| `.env`, `secrets/`, generated host secrets | host | operator's password manager + `host_vars/*.secrets.yml` copy; **encrypted** tarball into the backup archive when `BACKUP_INCLUDE_ENV=true` | on change |
| Caddy certificates/CA (`caddy-data`) | volume | included in the host tarball; re-issuable for public hosts, must be kept for `tls internal` roots | weekly |
| Compose files, Caddyfile | git + `/opt/smart-helpdesk` | git | — |

Not backed up: Valkey (queues are transient; a lost queue is re-driven from the database state by the SLA sweep and webhook retry), Horizon metrics, logs.

## spatie/laravel-backup configuration

```php
// config/backup.php (excerpt)
'backup' => [
    'name' => env('APP_NAME', 'smart-helpdesk'),
    'source' => [
        'files' => ['include' => [], 'exclude' => []],          // attachments are in S3, not on disk
        'databases' => ['pgsql'],
    ],
    'database_dump_compressor' => Spatie\DbDumper\Compressors\GzipCompressor::class,
    'destination' => [
        'compression_method' => ZipArchive::CM_DEFLATE,
        'disks' => ['backups'],
    ],
    'password' => env('BACKUP_ARCHIVE_PASSWORD'),
    'encryption' => 'default',                                   // AES-256 zip
],
'cleanup' => [
    'default_strategy' => [
        'keep_all_backups_for_days' => 7,
        'keep_daily_backups_for_days' => 7,
        'keep_weekly_backups_for_weeks' => 4,
        'keep_monthly_backups_for_months' => 3,
        'keep_yearly_backups_for_years' => 0,
        'delete_oldest_backups_when_using_more_megabytes_than' => 20000,
    ],
],
'monitor_backups' => [[
    'name' => env('APP_NAME'),
    'disks' => ['backups'],
    'health_checks' => [
        Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
        Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 20000,
    ],
]],
'notifications' => ['mail' => ['to' => env('HEALTH_NOTIFY_MAIL')]],
```

The `backups` disk is a second S3 disk with **separate credentials** limited to the backup bucket/prefix (on-prem: a second RustFS bucket or the customer's backup S3; cloud: a second Spaces bucket in another region). The dump uses `pg_dump` from `postgresql-client-18` in the image with `--no-owner --no-acl` so it restores under either role. Because the runtime role `helpdesk_app` is subject to RLS and would dump zero tenant rows, the `backups` database connection uses a dedicated `helpdesk_backup` role: read-only grants on all tables, `BYPASSRLS`, no login from the application connection (created by `infra/postgres/init/01-roles.sql`).

Schedule ([11-operations/scheduler.md](../11-operations/scheduler.md)): `backup:clean` 01:00, `backup:run --only-db` 01:30, `backup:monitor` 02:00, `storage:sync-backup` 03:00.

## Host safety net

```sh
#!/bin/sh
# /usr/local/bin/helpdesk-pg-dump.sh [label]
set -eu
DIR=/opt/smart-helpdesk/backups; LABEL=${1:-daily}
mkdir -p "$DIR"
docker compose -f /opt/smart-helpdesk/compose.yaml exec -T postgres \
  pg_dump -U helpdesk_owner -d helpdesk --no-owner --no-acl | gzip > "$DIR/helpdesk-$(date +%F-%H%M)-$LABEL.sql.gz"
find "$DIR" -name '*-daily.sql.gz' -mtime +7 -delete
```

Runs from a systemd timer (`helpdesk-backup.timer`, daily 02:30) installed by the Ansible `backup` role, and before every deploy with label `pre-deploy`. It works even when Laravel is broken, which is its whole purpose.

## Object storage

RustFS supports bucket versioning and replication; enable versioning on `helpdesk` in on-prem installs with enough disk, otherwise rely on the nightly sync. Cloud providers: enable versioning on the primary bucket (cheap) and use a different region for the backup bucket.

## Verification

| Check | Frequency | How |
|---|---|---|
| `backup:monitor` | daily | fails health if the newest backup is older than 1 day or storage exceeds the cap; mail notification |
| `BackupsCheck` in `/health` | continuous | surfaces in the health JSON used by uptime monitors |
| Restore rehearsal | weekly in dev/staging, before the demo, and once during customer onboarding | `restore.yml` into a scratch Compose project; run the isolation test suite and open two tenants |
| Dump integrity | daily | `gzip -t` in the host script; spatie archive listing |

## Encryption

Archives are AES-256 zip-encrypted with `BACKUP_ARCHIVE_PASSWORD` (generated per install); host dumps are plain gzip on the host disk (0600, `deploy`) and are not shipped anywhere. The password lives in the host secrets file; losing it makes archives unreadable, so it is part of the operator's password-manager entry.

## On-prem versus cloud

| | On-prem | Cloud reference |
|---|---|---|
| Primary DB backup | spatie → customer backup bucket or second RustFS bucket | spatie → second Spaces bucket, other region |
| Attachments | nightly sync to backup bucket; RustFS versioning if disk allows | Spaces versioning + nightly sync |
| Host dumps | 7 days on host disk | 7 days on droplet volume |
| Off-site | customer responsibility (documented) | different region by default |
| Restore drill | during onboarding, then quarterly | weekly in staging |
