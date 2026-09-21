# Disaster recovery

| Target | MVP | V1 |
|---|---|---|
| RPO (data loss) | 24 h (daily backups) | 1 h (WAL archiving with pgBackRest) |
| RTO (time to service) | 4 h | 30 min (warm standby) |

Runbooks for day-to-day failures are in [11-operations/runbooks.md](../11-operations/runbooks.md); this page covers loss scenarios.

## Scenarios

| Scenario | Detection | Response |
|---|---|---|
| VM lost or unbootable | uptime monitor on `/health`; provider alert | full rebuild (procedure below) |
| Database corruption | `DatabaseCheck` fails; PostgreSQL logs; app 500s | stop app/horizon/scheduler; restore latest dump into a fresh volume; accept RPO |
| Bad migration deployed | health/tests fail after deploy | rollback image; `migrate:rollback` if reversible, else restore the `pre-deploy` dump taken by `deploy.yml` |
| Object storage lost | `StorageCheck` fails; downloads 404 | recreate bucket; sync back from the backup bucket; attachments created after the last sync are lost (audit log lists them) |
| Secret leak (`APP_KEY`, DB, S3, client secrets) | report, audit anomalies | rotate per [runbooks](../11-operations/runbooks.md#rotate-app_key-and-secrets); revoke Passport clients; force logout (`sessions` truncate) |
| Tenant asks for data deletion/export | ticket from tenant admin | `tenants:export {slug}` (JSON + attachment archive) then `tenants:purge {slug}` (V1 hard delete; MVP archives) |
| Ransomware/host compromise | unexpected changes, health failures | treat as VM lost: rebuild from clean images and last known-good backup; rotate all secrets |

## Full rebuild procedure

Assumes: the newest `helpdesk-*.dump` and `objects-*.tar.gz` off the host (`backup.yml -e fetch_backup=true`; spatie archives once V1-PL-16 lands), the operator's copy of `host_vars/<host>.secrets.yml` (APP_KEY, DB, Valkey and S3 passwords) and the git repository.

1. **Provision** (cloud): `cd infra/tofu/envs/reference && tofu apply` → new droplet, same DNS names (the `dns` module updates records). On-prem: obtain a new VM from the customer with Docker-capable Debian/Ubuntu and add it to the inventory.
2. **Configure**: `ansible-playbook -i inventory/<env>.ini site.yml` with the saved secrets file in place so the same passwords are re-used (otherwise the archive password will not match). The playbook brings up an empty, healthy stack.
3. **Restore database and attachments**: `ansible-playbook -i inventory/<env>.ini restore.yml -e upload=true -e dump_file=<local .dump> -e objects_file=<local objects .tar.gz>` (procedure below). With an external bucket instead of RustFS, the attachments are restored from that provider's versioning or backup bucket (`storage:sync-backup --reverse` arrives with V1-PL-16).
5. **Verify**: `restore.yml` ends with `smoke.sh`; then `HEALTH_TOKEN=… just prod-smoke <domain>`, sign in to two workspaces, open a ticket with an attachment and download it, check `/horizon` processes jobs.
7. **Communicate**: platform status mail to tenant owners with the data-loss window (last backup time → incident time).

Expected duration on a 2 vCPU VM with a 5 GB database: provision 5 min, configure 6 min, restore 20–40 min, attachments depends on volume; within the 4 h RTO.

## Restore procedure (as built)

`helpdesk-restore <dump> [objects.tar.gz]` ([`infra/scripts/helpdesk-restore.sh`](../../infra/scripts/helpdesk-restore.sh)), run by `restore.yml` or by hand in `/opt/smart-helpdesk` (`RESTORE_YES=1` skips the prompt):

```sh
docker compose exec -T postgres pg_restore --list < "$dump" > /dev/null          # readable before anything is dropped
docker compose stop proxy app horizon scheduler reverb
docker compose exec -T postgres dropdb -U postgres --if-exists --force helpdesk
docker compose exec -T postgres pg_restore -U postgres --create --exit-on-error -d postgres < "$dump"
docker compose exec -T rustfs sh -c 'find /data -mindepth 1 -maxdepth 1 -exec rm -rf {} + && tar -C /data -xzf -' < "$objects"
docker compose restart rustfs
docker compose run --rm --no-deps migrate                                        # migrations newer than the dump, owner role
docker compose run --rm --no-deps migrate php artisan cache:clear                # stale report caches and heartbeats
docker compose up -d --wait
docker compose exec -T app php artisan storage:ensure-bucket --check
```

Valkey is not restored: queued jobs are transient (the SLA sweep and webhook retries re-derive work from the database).

## Drill 2026-09-21

| Drill | Steps | Duration | Result |
|---|---|---|---|
| Host loss, prod-like stack on the workstation (`docker compose -p shp-prodtest`, production images and overlay) | 3 tickets created through the API → `helpdesk-backup manual` (308 KB dump, 8 KB objects) → a 4th ticket → `docker compose down -v` (all volumes gone) → fresh `up` of postgres/valkey/rustfs (roles recreated by the init script) → `helpdesk-restore` | backup < 1 s; restore 23 s | 3 tickets, 1 user, 1 tenant; after the restore 42 tables have forced RLS and 66 triggers exist (schema objects come back with the dump); the owner signs in with the pre-backup password; smoke passes (health 503 for the known disk/heartbeat reasons, production.md §As built). The 4th ticket is lost, as the RPO says |
| Ansible `restore.yml` on the rehearsal host (single-tenant) | `backup.yml -e fetch_backup=true` → 4th ticket → `restore.yml -e upload=true` with the fetched files | < 1 min | ticket count back to 3, sign-in and smoke pass |

## Partial restores

- Single tenant: restore the dump into a scratch database, `COPY` the tenant's rows by `tenant_id` in FK order (script `restore-tenant.sh`, V1 hardened), and sync `tenants/{id}/` keys. MVP documents the procedure; it is not automated.
- Single table (e.g. accidental role deletion): restore scratch database, copy rows manually.

## Test schedule

| Drill | When |
|---|---|
| Restore latest dump into a scratch Compose project and run the test suite | weekly (CI job `restore-drill` on a schedule, milestone 3 onward) |
| Full rebuild on a throwaway droplet | once before the demo; quarterly after |
| Secret rotation | once during milestone 3 hardening |

Results are logged in `docs/11-operations/runbooks.md` drill log (date, duration, issues).
