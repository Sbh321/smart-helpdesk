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

See [09-infrastructure/disaster-recovery.md](../09-infrastructure/disaster-recovery.md#restore-procedure-as-built) for the steps and the full rebuild. On a live host (destructive: the database is replaced):

```sh
ls -t /opt/smart-helpdesk/backups | head                        # helpdesk-<UTC>-<label>.dump, objects-<UTC>-<label>.tar.gz
sudo HELPDESK_DIR=/opt/smart-helpdesk helpdesk-restore /opt/smart-helpdesk/backups/helpdesk-20261001T023000Z-daily.dump \
  /opt/smart-helpdesk/backups/objects-20261001T023000Z-daily.tar.gz
cd /opt/smart-helpdesk && PLATFORM_DOMAIN=<domain> HOST_LAYOUT=<split|single> SMOKE_CONNECT=127.0.0.1:443 SMOKE_INSECURE=1 SMOKE_DEV=0 ./infra/scripts/smoke.sh
```

From the controller: `ansible-playbook -i inventory/<env>.ini restore.yml -e dump_file=… [-e objects_file=…] [-e upload=true]`. Spatie archives (`backup:list`, unzip with `BACKUP_ARCHIVE_PASSWORD`) arrive with V1-PL-16.

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
- **Prevention**: `caddy-data` volume persisted and backed up, uptime monitor with TLS expiry check for all nine hosts.

## Outbound mail: relay (port 25 blocked)

Cloud providers block **outbound** port 25 (AWS EC2 by default, GCP always, many VPS hosts): Stalwart then cannot reach the recipients' MX hosts, messages stay in its queue and its log shows `delivery.connect` timeouts to port 25. The fix is a smart host on 587. The application does not change: Laravel still submits to `mail:587`, Stalwart still signs with the platform's DKIM key and then hands every remote message to the relay ([production.md §Mail](../09-infrastructure/production.md#mail)).

```ini
# .env on the server (Ansible: mail_relay_host, mail_relay_port, mail_relay_username, vault_mail_relay_password)
MAIL_RELAY_HOST=email-smtp.ap-south-1.amazonaws.com
MAIL_RELAY_PORT=587
MAIL_RELAY_USERNAME=AKIA…                 # SES SMTP user name (not the IAM access key of a person)
MAIL_RELAY_PASSWORD=…                     # SES SMTP password
MAIL_RELAY_IMPLICIT_TLS=false             # STARTTLS on 587; true only for port 465
```

Then `./infra/scripts/mail-init.sh` (it prints `remote route: 'relay'`), and test with `docker compose exec app php artisan mail:send-test you@gmail.com --workspace=Demo`. Any SMTP relay works the same way (Postmark, Brevo, Mailgun, the customer's Exchange); Mailpit is the development relay.

### Amazon SES for `shp.subhambhandari.com.np` (the AWS environment, ap-south-1)

**As built (2026-09-22): automated.** `infra/tofu/envs/aws/mail.tf` creates steps 1–3: the domain identity with Easy DKIM (RSA 2048) and the custom MAIL FROM `bounce.shp…`, every Cloudflare record below (DNS only), the sandbox recipients from `ses_verified_recipients`, and an IAM user limited to `ses:SendRawEmail` from `*@shp.subhambhandari.com.np` whose SMTP password OpenTofu derives (`tofu output -raw ses_smtp_username` / `ses_smtp_password`, kept in the encrypted state). Stalwart's own DKIM record follows once `stalwart_dkim_selector` and `stalwart_dkim_public_key` are set from the `mail-init.sh` output. Step 4 (production access) stays with the owner. Verified: identity `verified`, DKIM and MAIL FROM `SUCCESS`; `mail:send-test success@simulator.amazonses.com` → Stalwart logged `delivery.delivered` through the relay over STARTTLS; Stalwart uses about 160 MB on the t3.small. Two fixes found on the way: the relay secret's variant is `Value` in Stalwart 0.16 (`mail-init.sh` had `Text`, rejected as `invalidPatch`), and Debian's `exim4` held `127.0.0.1:25`, which stopped Docker publishing port 25 (the `common` role masks it when `mail_profile` is on).

The manual steps below remain the reference for any other host.

1. **Domain identity.** SES console, region *Asia Pacific (Mumbai) ap-south-1* (same as the VM) → *Configuration → Identities → Create identity* → *Domain* `shp.subhambhandari.com.np`, *Easy DKIM*, RSA 2048-bit, "Publish DNS records to Route 53" off. Optional but recommended: *Use a custom MAIL FROM domain* `bounce.shp.subhambhandari.com.np`, behaviour on MX failure "Use default MAIL FROM domain".
2. **DNS in Cloudflare** (zone `subhambhandari.com.np`, every record **DNS only**, grey cloud; a proxied CNAME breaks DKIM):
   - the three CNAMEs SES shows: `<token>._domainkey.shp` → `<token>.dkim.amazonses.com` (×3);
   - custom MAIL FROM: MX `bounce.shp` → `10 feedback-smtp.ap-south-1.amazonses.com`, TXT `bounce.shp` → `v=spf1 include:amazonses.com ~all`;
   - the platform's own records from `mail-init.sh` (production.md §DNS): MX `shp` → `10 mail.shp.subhambhandari.com.np`, TXT `shp` → `v=spf1 mx -all`, TXT `<selector>._domainkey.shp` → Stalwart's DKIM key, TXT `_dmarc.shp` → `v=DMARC1; p=none; rua=mailto:postmaster@shp.subhambhandari.com.np`.
   Wait until the identity shows *Verified* and *DKIM: Successful* (minutes to an hour).
3. **SMTP credentials.** *SMTP settings → Create SMTP credentials*: SES creates an IAM user limited to `ses:SendRawEmail` and shows the SMTP user name and password once. Put them in the vault (`vault_mail_relay_password`) and the inventory (`mail_relay_username`). The password may contain `+`, `/` and `=`; `mail-init.sh` accepts those.
4. **Sandbox.** A new SES account can send only to verified addresses (200 a day). For the first test, verify your own mailbox (*Create identity → Email address*) and send to it. Then *Account dashboard → Request production access*: mail type *Transactional*, website `https://shp.subhambhandari.com.np`, use case "helpdesk notifications and replies to support requests of workspaces on this platform; recipients are users and the contacts who opened a request; bounces and complaints are monitored in SES and the platform's inbound mailbox", and confirm you only send to addresses that asked for a reply. AWS answers within about a day.
5. **Apply.** `ansible-playbook -i inventory/aws.ini site.yml -e @…/aws-vars.yml` with `mail_profile: true` and the relay variables (or edit `.env` and run `./infra/scripts/mail-init.sh` on the server), then add the two `MAIL_DKIM_*` lines it prints (`mail_dkim_selector`, `mail_dkim_public_key` in Ansible).
6. **Verify at Gmail.** `docker compose exec app php artisan mail:send-test you@gmail.com --workspace=Demo`; in Gmail *⋮ → Show original*: `SPF: PASS` (for `bounce.shp…` with a custom MAIL FROM, otherwise for `amazonses.com`), `DKIM: 'PASS' with domain shp.subhambhandari.com.np` (Stalwart's signature and SES's Easy DKIM signature both carry `d=shp.subhambhandari.com.np`), `DMARC: 'PASS'`. Reply to the message: it goes to `ticket+…@shp.subhambhandari.com.np`, arrives on port 25 of the VM and lands in the `inbound` mailbox (Stalwart logs `queue.message-queued … to = ["inbound@…"]`, then a `local` delivery).

### Brevo as the relay while SES production access is pending (2026-09-22)

SES answered the first production-access request by asking for more detail (case 179007035300169); until it is granted, SES delivers only to verified addresses. Brevo's free plan (300 mails a day, no expiry) relays in the meantime. Only the last outbound hop changes; the application, the sender addresses, Stalwart's DKIM signature and inbound mail stay as they are.

- **DNS** (`infra/tofu/envs/aws/mail.tf`, `brevo = { code = "…" }` in `terraform.tfvars`): the `brevo-code` TXT on the platform domain, CNAMEs `brevo1._domainkey` and `brevo2._domainkey` → `b1`/`b2.<domain-with-dashes>.dkim.brevo.com`, and Brevo's report address added to DMARC `rua`. In Brevo: *Individual DNS records*, added by us; no branded subdomain (`mail.` is the MX host). No SPF change: Brevo signs with `d=<platform domain>`, so DMARC passes on DKIM; SPF passes for Brevo's own bounce domain and is not aligned.
- **Credentials**: Brevo *SMTP & API → SMTP*: login `…@smtp-brevo.com` and an SMTP key (`xsmtpsib-…`), kept in the operator's private Ansible extra-vars (`mail_relay_username`, `mail_relay_password`) with `mail_relay_host: smtp-relay.brevo.com`, port 587; then `site.yml`, which re-runs `mail-init.sh`. Verified: `AUTH 235` from the server over STARTTLS.
- **Bounces** stay with Brevo (its transactional log and block list) instead of returning to our mailbox through `bounce.shp…`.

**Switching back to SES** once `aws sesv2 get-account --query ProductionAccessEnabled` is `true`: set `mail_relay_host: email-smtp.ap-south-1.amazonaws.com` and the credentials from `tofu output -raw ses_smtp_username` / `ses_smtp_password` (in `infra/tofu/envs/aws`), run `site.yml`, send `mail:send-test` to a real inbox and check SPF, DKIM and DMARC. The SES identity and records never went away, so no DNS change is needed. Later, `brevo = null` and `tofu apply` remove the Brevo records.

The alternative to a relay on AWS is the *Request to remove email sending limitations* form (EC2 port 25, together with a reverse DNS record `mail.shp.subhambhandari.com.np` for the Elastic IP); then leave `MAIL_RELAY_HOST` empty and add a PTR. A relay is simpler and gives better deliverability from a fresh IP.

- **Diagnosis**: `docker compose logs mail | grep -E 'delivery\.|queue\.'`; queued messages: `docker compose run --rm -T mail-cli query QueuedMessage` (with `MAIL_ADMIN_PASSWORD` in `.env`); a relay that refuses the credentials shows up as an authentication error on the `delivery.*` lines; SES in the sandbox answers `554 Message rejected: Email address is not verified`.
- **Prevention**: SES reputation dashboard and bounce/complaint notifications (V1-ML-03 processes them in the application); keep `MAIL_DMARC_POLICY=none` until the DMARC reports look clean, then `quarantine`.

## Drill log

| Date | Drill | Duration | Outcome / issues |
|---|---|---|---|
| 2026-09-21 | restore into scratch project (`shp-prodtest`, after `down -v`) | backup < 1 s, restore 23 s | data back to the backup point, sign-in works; details in [disaster-recovery.md](../09-infrastructure/disaster-recovery.md#drill-2026-09-21) |
| 2026-09-21 | `restore.yml` on the Ansible rehearsal host | < 1 min | green; smoke with sign-in passes |
| (milestone 3) | secret rotation | | |
| (before demo) | full rebuild on throwaway droplet | | |
