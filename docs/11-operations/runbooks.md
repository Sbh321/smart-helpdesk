# Runbooks

Each runbook: symptoms, diagnosis, fix, prevention. Commands assume `/opt/smart-helpdesk` (production) or the repo root (dev) and `docker compose` on the host. Platform admin URLs are on the central domain.

## Service down

- **Symptoms**: uptime monitor fails on `/health`; browser cannot connect; `docker compose ps` shows a container `exited` or `unhealthy`.
- **Diagnosis**: `docker compose ps`; `docker compose logs --tail=200 <service>`; `docker inspect --format '{{json .State.Health}}' smart-helpdesk-app-1`; `df -h`; `free -m`.
- **Fix**: `docker compose up -d --wait <service>`; if the image is missing, `docker compose pull`; if PostgreSQL will not start because of a full disk, see *Disk full*; if `app` is healthy but the proxy is not, `docker compose restart proxy` and check the Caddyfile with `docker compose exec proxy caddy validate --config /etc/caddy/Caddyfile`.
- **Prevention**: `restart: unless-stopped`, resource limits, disk alerting at 70 %.

## Queue backlog

- **Symptoms**: notifications late, SLA warnings late, Horizon shows growing "pending" and long wait; health `QueueCheck` fails.
- **Diagnosis**: `/horizon` → Monitoring/Metrics; `docker compose exec horizon php artisan horizon:status`; `docker compose logs horizon`; check Valkey memory `docker compose exec valkey valkey-cli -a $REDIS_PASSWORD info memory`.
- **Fix**: if Horizon is paused, `horizon:continue`; if a supervisor is saturated by `webhooks`, temporarily raise `maxProcesses` in `config/horizon.php` and `horizon:terminate` (S6 restarts it); if a poison job loops, `horizon:forget <id>` or `queue:forget`; if Valkey is out of memory (`noeviction` → `OOM command not allowed`), raise `--maxmemory` and clear stale cache tags.
- **Prevention**: `waits` alerts, separate supervisors per queue class, `retry_after` > timeouts.

## Failed jobs

- **Symptoms**: Horizon "Failed" tab non-empty; mail alert for `sla` failures.
- **Diagnosis**: open the job in Horizon (tags show `tenant:{id}`), read the exception; correlate by `request_id` in logs.
- **Fix**: after fixing the cause, `queue:retry <id>` or `queue:retry --queue=webhooks`; for webhook deliveries prefer the tenant UI "Retry" which resets the delivery state; for `EvaluateSlaTimers` simply wait for the next tick (idempotent).
- **Prevention**: explicit `tries`/`backoff`, idempotency keys, tests for each job's failure path.

## SLA sweep not running (heartbeat stale)

- **Symptoms**: `SlaSweepCheck` or `ScheduleCheck` failing; breaches not detected; `sla:last_sweep_at` older than 3 min.
- **Diagnosis**: `docker compose ps scheduler`; `docker compose logs scheduler`; `docker compose exec app php artisan schedule:list`; check the `onOneServer` lock is not stuck: `valkey-cli -a $REDIS_PASSWORD keys 'sh:production:*framework/schedule*'`.
- **Fix**: `docker compose restart scheduler`; if a lock is stale (previous container killed mid-task), delete the lock key or wait for its TTL (24 h max → delete it); run `php artisan sla:evaluate` once manually to catch up (safe: idempotent, events fire once).
- **Prevention**: `stop_grace_period`, heartbeat healthcheck restarts hung schedulers, `withoutOverlapping` expiry set to 10 min for the sweep.

## Webhook endpoint failing for a tenant

- **Symptoms**: tenant reports missing events; subscription shows `consecutive_failures` rising or `disabled_at` set after 25 failures; deliveries `failed`/`dead`.
- **Diagnosis**: tenant Settings → Developer → delivery log (status code, response excerpt, attempt times); `docker compose logs horizon | jq 'select(.context.subscription_id=="…")'`; test the URL from the host: `curl -sv -X POST <url>`; check SSRF denylist did not block a newly private IP.
- **Fix**: tenant fixes their endpoint; they (or a platform admin) click "Re-enable" and "Retry dead deliveries" (`webhooks:replay --subscription=<id> --since=…` for bulk); rotate the secret if they suspect leakage.
- **Prevention**: automatic disable with notification to the tenant developer, documented signature verification snippet, `webhook-echo` for testing.

## Storage unreachable

- **Symptoms**: uploads fail at "intent" (502 `storage_unavailable`), downloads redirect to URLs that time out, `StorageCheck` fails.
- **Diagnosis**: `docker compose ps rustfs` (on-prem) or provider status; `docker compose exec app php artisan storage:check` (HEAD a known key); verify `AWS_ENDPOINT` and `AWS_USE_PATH_STYLE_ENDPOINT`; check bucket CORS with `php artisan storage:ensure-bucket --check`.
- **Fix**: restart `rustfs`; fix credentials/endpoint in `.env` and `docker compose up -d app horizon`; re-run `storage:ensure-bucket` after a fresh bucket; pending attachment intents will expire and users retry.
- **Prevention**: health check, path-style setting driven from inventory, RustFS pinned tag.

## Disk full

- **Symptoms**: PostgreSQL refuses writes (`No space left on device`), containers restart, health disk check failing.
- **Diagnosis**: `df -h`; `docker system df`; `du -sh /var/lib/docker/volumes/*`; `du -sh /opt/smart-helpdesk/backups`.
- **Fix**: `docker system prune -f` (dangling images from upgrades are the usual culprit); delete old host dumps beyond retention; if RustFS data is the cause, apply lifecycle/retention or move the volume to a larger disk; only then `docker compose up -d`.
- **Prevention**: json-file rotation, backup retention, `UsedDiskSpaceCheck` at 70 %, `docker image prune` step in `deploy.yml`.

## Restore from backup

See [09-infrastructure/disaster-recovery.md](../09-infrastructure/disaster-recovery.md) for the full rebuild. Database-only restore on a live host:

```sh
docker compose stop app horizon scheduler
docker compose exec -T postgres psql -U helpdesk_owner -d postgres -c "DROP DATABASE helpdesk;" -c "CREATE DATABASE helpdesk;"
gunzip -c backups/helpdesk-2026-10-01-0230-daily.sql.gz | docker compose exec -T postgres psql -U helpdesk_owner -d helpdesk
docker compose exec -T postgres psql -U helpdesk_owner -d helpdesk -f /docker-entrypoint-initdb.d/01-roles.sql   # grants for helpdesk_app/backup
docker compose run --rm -e DB_USERNAME=helpdesk_owner -e DB_PASSWORD_FILE=/run/secrets/db_owner_password app php artisan migrate --force
docker compose up -d --wait
docker compose exec app php artisan health:check
```

Spatie archives: `php artisan backup:list`, download the zip from the `backups` disk, unzip with `BACKUP_ARCHIVE_PASSWORD`, then the same `psql` import.

## Rotate APP_KEY and secrets

- **APP_KEY** (encrypts webhook/client secrets and the `encrypted` casts): `php artisan key:generate --show`, set `APP_PREVIOUS_KEYS` to the old key, set the new `APP_KEY`, `docker compose up -d`; Laravel decrypts with previous keys transparently; run `php artisan encryption:re-encrypt` (custom command iterating encrypted columns) then remove the previous key. Sessions are invalidated: announce a re-login.
- **DB passwords**: `ALTER ROLE helpdesk_app PASSWORD '…'` via `psql`, update `secrets/db_app_password`, `docker compose up -d --force-recreate app horizon scheduler`.
- **Valkey password**: update `.env` `REDIS_PASSWORD`, recreate `valkey` (queue contents are lost: drain first with `horizon:pause`, wait for empty queues) and the backend services.
- **S3 keys**: create new keys at the provider/RustFS, update `.env`, recreate backend services, delete old keys.
- **Passport keys**: `passport:keys --force` invalidates all client tokens (1 h max lifetime anyway); tenants' clients re-authenticate automatically.
- **Prevention**: secrets file in the operator's password manager, rotation rehearsed once in milestone 3.

## Suspend a tenant

- Platform admin UI → tenant → Suspend, or `php artisan tenants:suspend acme --reason="non-payment"`; middleware returns 403 `tenant_suspended` for UI and API; scheduled tasks skip suspended tenants; webhooks pause. Reactivate with `tenants:reactivate acme`. Both are audited.

## Reset the demo environment

```sh
just demo-reset            # dev: truncates tenant data, reseeds DemoSeeder, resets the demo clock offset, re-registers webhook-echo
just demo-tick 30          # advance the demo clock 30 minutes and run the SLA sweep
```

Only available when `APP_ENV != production`. Takes under a minute; rehearse before the presentation ([12-academic/demo-plan.md](../12-academic/demo-plan.md)).

## Upgrade PostgreSQL major

- Take a full dump and a host snapshot. Bring up a second `postgres` service on the new major with a new volume, `pg_dumpall | psql` into it (or `pg_upgrade` via the `pgautoupgrade` image if preferred), point `DB_HOST` at it, run the isolation schema tests, then retire the old volume. Do it in a maintenance window; not planned before V1 (PG18 is supported to 2030).

## Certificate renewal failure

- **Symptoms**: browser TLS warnings on one of the `*.shp` hosts; Caddy logs `obtaining certificate failed`.
- **Diagnosis**: `docker compose logs proxy | jq 'select(.logger=="tls")'`; confirm DNS for the failing host (`dig app.shp.subhambhandari.com.np`), that ports 80/443 are reachable from the internet (ufw, provider firewall), and rate-limit messages from Let's Encrypt.
- **Fix**: correct DNS/firewall; Caddy retries automatically; for customer-provided certificates replace files in `certs/` and `docker compose exec proxy caddy reload --config /etc/caddy/Caddyfile`; for `tls internal` roots that expired (10 years, unlikely) re-trust the new root.
- **Prevention**: `caddy-data` volume persisted and backed up, uptime monitor with TLS expiry check for all eight hosts.

## Drill log

| Date | Drill | Duration | Outcome / issues |
|---|---|---|---|
| (milestone 3) | restore into scratch project | | |
| (milestone 3) | secret rotation | | |
| (before demo) | full rebuild on throwaway droplet | | |
