# Backups

Rule: a backup that lives on the same disk as the database is not a backup, and an untested restore is not a backup. Targets: RPO 24 h, RTO 4 h ([disaster-recovery.md](disaster-recovery.md)).

**As built in M3-14:** the host safety net below (database dump + RustFS archive, systemd timer, pre-deploy dump, `backup.yml -e fetch_backup=true` for an off-host copy) is implemented and its restore was rehearsed. `spatie/laravel-backup` (encrypted archives to a second bucket, `backup:monitor`, the `BackupsCheck`) is **deferred**: adding it needs a Composer dependency, `config/backup.php`, a `backups` disk and scheduler entries in the backend, which is backend work outside this task. MVP-SHORTCUT: off-host copies are the operator's `backup.yml -e fetch_backup=true` (or any file sync of `/opt/smart-helpdesk/backups`); V1: V1-PL-16. The spatie sections below remain the target design.

## What is backed up

| Asset | Where it lives | Mechanism | Frequency |
|---|---|---|---|
| PostgreSQL (all tenants, control plane, Passport, audit) | `pg-data` volume | host `helpdesk-backup` timer → `/opt/smart-helpdesk/backups` (built); `spatie/laravel-backup` → `backups` disk (deferred, V1-PL-16) | daily 02:30 (host), 01:30 (spatie) |
| Attachments on the bundled RustFS (`storage` profile) | `object-data` volume | host `helpdesk-backup`: `tar` of the RustFS data directory next to the dump (built) | daily 02:30 |
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

The `backups` disk is a second S3 disk with **separate credentials** limited to the backup bucket/prefix (on-prem: a second RustFS bucket or the customer's backup S3; cloud: a second Spaces bucket in another region). The dump uses `pg_dump` from `postgresql-client-18` in the image with `--no-owner --no-acl` so it restores under either role. Because the runtime role `helpdesk_app` is subject to RLS and would dump zero tenant rows, the `backups` database connection uses a dedicated `helpdesk_backup` role: read-only grants on all tables, `BYPASSRLS`, no login from the application connection (created by `infra/postgres/init/10-roles-and-databases.sh`; V1-PL-16 must also grant it `SELECT` on sequences, which the host dump avoids by running as `postgres`).

Schedule ([11-operations/scheduler.md](../11-operations/scheduler.md)): `backup:clean` 01:00, `backup:run --only-db` 01:30, `backup:monitor` 02:00, `storage:sync-backup` 03:00.

## Host safety net (as built)

[`infra/scripts/helpdesk-backup.sh`](../../infra/scripts/helpdesk-backup.sh), installed as `/usr/local/bin/helpdesk-backup` by the Ansible `backup` role:

```sh
helpdesk-backup [label]        # label: daily (timer) | pre-deploy (deploy.yml) | manual (backup.yml)
# writes, mode 0600 in /opt/smart-helpdesk/backups:
#   helpdesk-<UTC timestamp>-<label>.dump      pg_dump -U postgres -d helpdesk --create --format=custom --compress=6
#   objects-<UTC timestamp>-<label>.tar.gz     tar of the RustFS /data (only when the rustfs service runs)
# integrity: pg_restore --list must show the tenants table data; gzip -t on the archive; files appear only when complete
# retention: *-daily.* older than RETENTION_DAYS (7) are deleted; pre-deploy and manual files are kept
```

Design decisions, changed from the first draft:

- **The dump runs as the `postgres` superuser** over the container's local socket. `helpdesk_owner` owns the tables but `FORCE ROW LEVEL SECURITY` (M3-07) applies to it too, and `pg_dump` refuses tables whose policies would hide rows; `helpdesk_app` sees one workspace at a time. `helpdesk_backup` (`BYPASSRLS`) would work for tables but lacks `SELECT` on sequences, so it stays reserved for the spatie job.
- **Custom format with `--create`, ownership and grants kept.** A restore recreates the database with its owner, grants, RLS policies and change-capture triggers exactly; nothing has to re-apply `infra/postgres/init` grants. RLS and triggers are post-data items, so rows load before policies and triggers exist (no policy checks, no history rows written during restore). Roles are cluster-level and are not in the dump; any host initialised from the same `secrets/` (or from new ones) has them.
- The timer is `helpdesk-backup.timer` (daily 02:30 UTC, `RandomizedDelaySec=10min`, `Persistent=true` so a missed run happens at boot); `deploy.yml` runs it with label `pre-deploy`. It works even when Laravel is broken, which is its whole purpose.
- Off-host: `ansible-playbook -i inventory/<env>.ini backup.yml -e fetch_backup=true` copies the newest pair to `infra/ansible/backups/<host>/` on the controller.

Restore: [`helpdesk-restore`](../../infra/scripts/helpdesk-restore.sh) / `restore.yml` ([disaster-recovery.md](disaster-recovery.md#restore-procedure-as-built)).

## Object storage

RustFS supports bucket versioning and replication; enable versioning on `helpdesk` in on-prem installs with enough disk, otherwise rely on the nightly sync. Cloud providers: enable versioning on the primary bucket (cheap) and use a different region for the backup bucket.

## Verification

| Check | Frequency | How |
|---|---|---|
| `backup:monitor` | daily | fails health if the newest backup is older than 1 day or storage exceeds the cap; mail notification |
| `BackupsCheck` in `/health` | continuous | surfaces in the health JSON used by uptime monitors |
| Restore rehearsal | weekly in dev/staging, before the demo, and once during customer onboarding | `restore.yml` (or `helpdesk-restore`) into a scratch Compose project; smoke with sign-in; open two tenants. First rehearsal: 2026-09-21 ([disaster-recovery.md](disaster-recovery.md#drill-2026-09-21)) |
| Dump integrity | every run | `pg_restore --list` and `gzip -t` in `helpdesk-backup`; spatie archive listing (V1-PL-16) |

## Encryption

Archives are AES-256 zip-encrypted with `BACKUP_ARCHIVE_PASSWORD` (generated per install, with spatie, V1-PL-16); host dumps are compressed but not encrypted, 0600 in a 0700 directory, and are shipped only by the operator's explicit `backup.yml -e fetch_backup=true`; encrypt them at rest on the controller. The password lives in the host secrets file; losing it makes archives unreadable, so it is part of the operator's password-manager entry.

## On-prem versus cloud

| | On-prem | Cloud reference |
|---|---|---|
| Primary DB backup | spatie → customer backup bucket or second RustFS bucket | spatie → second Spaces bucket, other region |
| Attachments | nightly sync to backup bucket; RustFS versioning if disk allows | Spaces versioning + nightly sync |
| Host dumps | 7 days on host disk | 7 days on droplet volume |
| Off-site | customer responsibility (documented) | different region by default |
| Restore drill | during onboarding, then quarterly | weekly in staging |
