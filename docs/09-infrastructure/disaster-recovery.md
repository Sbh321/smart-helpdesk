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

Assumes: the latest spatie archive in the backup bucket, the operator's copy of `host_vars/<host>.secrets.yml` (contains DB, Valkey, S3 and archive passwords) and the git repository.

1. **Provision** (cloud): `cd infra/tofu/envs/reference && tofu apply` → new droplet, same DNS names (the `dns` module updates records). On-prem: obtain a new VM from the customer with Docker-capable Debian/Ubuntu and add it to the inventory.
2. **Configure**: `ansible-playbook -i inventory/<env>.ini site.yml` with the saved secrets file in place so the same passwords are re-used (otherwise the archive password will not match). The playbook brings up an empty, healthy stack.
3. **Restore the database**: `ansible-playbook restore.yml -e dump_file=<path or s3 url>`; the playbook stops `app`, `horizon`, `scheduler`, runs `dropdb/createdb`, `pg_restore`/`psql` as `helpdesk_owner`, re-applies `infra/postgres/init` grants, then `migrate --force` for any migrations newer than the dump.
4. **Restore attachments**: `php artisan storage:sync-backup --reverse` copies the backup bucket back into `helpdesk`.
5. **Restart** services; `php artisan up`; `health:check`.
6. **Verify**: log in to two tenants, open a ticket with an attachment and download it, check `/horizon` processes jobs, confirm `sla:evaluate` heartbeat, run `php artisan tenancy:verify-isolation` (the schema assertions from the isolation suite).
7. **Communicate**: platform status mail to tenant owners with the data-loss window (last backup time → incident time).

Expected duration on a 2 vCPU VM with a 5 GB database: provision 5 min, configure 6 min, restore 20–40 min, attachments depends on volume; within the 4 h RTO.

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
