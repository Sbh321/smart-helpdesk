# Production deployment (single server)

Applies to on-premise servers and the reference cloud VM alike; the differences are configuration ([deployment architecture](../03-architecture/deployment.md), [ADR-0012](../adr/0012-deployment-architecture.md)). Hosts are prepared by [Ansible](ansible.md); cloud VMs are created by [OpenTofu](terraform.md) first.

## Sizing

| Tier | vCPU | RAM | Disk | Fits |
|---|---|---|---|---|
| Minimum | 2 | 4 GB | 40 GB SSD | ≤ 20 agents, ≤ 100k tickets, RustFS on the same host |
| Comfortable | 4 | 8 GB | 80 GB SSD | ≤ 100 agents, Reverb enabled |

Memory budget at the minimum tier: PostgreSQL 1 GB, app 768 MB, Horizon 768 MB, scheduler 256 MB, proxy 256 MB, Valkey 512 MB, RustFS 256 MB, OS ≈ 200 MB. Swap of 2 GB is configured by the `common` role as a safety margin.

**Queue workers.** Horizon's production block auto-balances the main queues (`sla`, `notifications`, `default`, `reports`) up to 10 workers with at least one per queue, and runs 4 webhook workers, 1 media worker and, with Reverb, 1 broadcast worker: about 9 idle PHP workers of 50–100 MB each. A demo host below the minimum tier sets a fixed small pool through the environment (Ansible `horizon_balance`, `horizon_max_processes`, `horizon_webhook_processes`):

| Setting | Default | Demo host (2 GB) |
|---|---|---|
| `HORIZON_BALANCE` | `auto` | `false`: every main worker takes all four queues in priority order, `sla` first |
| `HORIZON_MAX_PROCESSES` | `10` | `2` (without balancing this is also the minimum) |
| `HORIZON_WEBHOOK_PROCESSES` | `4` | `1` |

The broadcast supervisor starts only when `BROADCAST_CONNECTION=reverb`. The AWS demo host (t3.small, terraform.md §AWS environment) runs 4 workers in total: 2 main, 1 webhooks, 1 media; before the change Horizon held about 480 MB with 9 workers.

## Host layout

As built in M3-14 (the Ansible `app` role creates exactly this):

```text
/opt/smart-helpdesk/
├── compose.yaml                  # copied from the release
├── infra/compose/{prod,tools}.yaml
├── infra/postgres/{entrypoint.sh,init/10-roles-and-databases.sh}
├── infra/scripts/smoke.sh
├── .env                          # 0600 deploy:deploy; Compose settings AND backend settings (BACKEND_ENV_FILE=.env)
├── secrets/                      # 0750; db_{superuser,owner,app,backup}_password, oauth-{private,public}.key (owner uid 33, 0640)
├── certs/                        # tls_mode=files: fullchain.pem, privkey.pem
├── backups/                      # 0700; helpdesk-<UTC>-<label>.dump + objects-<UTC>-<label>.tar.gz (7 days for daily)
└── .seeded, .tenant-created, .admin-created   # first-run markers (.tenant-created holds the owner invitation link, 0600)
/usr/local/bin/helpdesk-backup, helpdesk-restore      # from infra/scripts, run by helpdesk-backup.timer
/var/lib/docker/volumes/smart-helpdesk_{pg-data,valkey-data,object-data,caddy-data,caddy-config}
```

`.env` sets `COMPOSE_FILE=compose.yaml:infra/compose/prod.yaml`, so a plain `docker compose …` in `/opt/smart-helpdesk` uses the production overlay and never the development override. Templates: [`infra/env/multi-tenant.env.example`](../../infra/env/multi-tenant.env.example) and [`infra/env/single-tenant.env.example`](../../infra/env/single-tenant.env.example) (Ansible renders the same keys from `roles/app/templates/env.j2`). The `deploy` user owns the tree and is in the `docker` group; nothing else is installed on the host but Docker.

What `infra/compose/prod.yaml` adds to `compose.yaml`: images by tag (`BACKEND_IMAGE`, `PROXY_IMAGE`, `pull_policy: missing`), `restart: unless-stopped`, memory and CPU limits (table in §Sizing), json-file rotation on every service, the Passport keys and `certs/` mounted read-only, the RustFS console off (`RUSTFS_CONSOLE_ENABLE=false`), and a one-shot `migrate` service (profile `ops`) that runs `php artisan migrate --force` with `DB_CONNECTION=pgsql_owner`. Only the proxy publishes ports; PostgreSQL, Valkey and RustFS have none.

## Profiles per deployment

| Profile | On-prem | Cloud | Purpose |
|---|---|---|---|
| (none) | always | always | proxy, app, horizon, scheduler, postgres, valkey |
| `storage` | default on | off (managed Spaces/R2) | RustFS container; omit if the customer supplies an S3 endpoint |
| `realtime` | optional | optional | Reverb (M3-16); requires `REALTIME_ENABLED=true`, `BROADCAST_CONNECTION=reverb` and `REVERB_APP_ID/KEY/SECRET` with `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`. Ansible sets all of them from `realtime_profile` (the key and secret are generated host secrets). The proxy serves the key in `config.json` and routes only the socket (`/app/*` on the api host, `/api/app/*` in the single layout) to Reverb. See [realtime.md](../03-architecture/realtime.md) |
| `demo` | never | never | demo fixtures |
| `ops` | on demand | on demand | `migrate` one-shot (`docker compose run --rm migrate`); never started by `up` |

Set `COMPOSE_PROFILES=storage,realtime` in `.env`; Ansible renders it from `storage_profile` and `realtime_profile`.

## TLS options

The Caddyfiles are baked into the proxy image (`frontend/docker/Caddyfile` for `HOST_LAYOUT=split`, `Caddyfile.single` for `single`); `TLS_MODE` in `.env` selects the snippet, so one image serves every option below.

| Situation | `TLS_MODE` / Caddyfile `tls` | Notes |
|---|---|---|
| Public DNS, SaaS | `acme`: one ACME certificate per fixed host (`shp`, `app`, `api`, `admin`, `monitor`, `docs`, `files`, `mail` under `subhambhandari.com.np`) | HTTP-01; no wildcard or on-demand TLS; certificates persist in the `caddy-data` volume; ports 80/443 must be reachable from the internet |
| Public DNS, single on-prem host | `acme` (automatic HTTPS for the one hostname) | HTTP-01; no wildcard needed |
| DNS not ready at first boot, or hosts added later | `on_demand`: `tls { on_demand }`, gated by `on_demand_tls { ask … }` | the certificate is requested at the first TLS handshake; the default ask endpoint (inside the proxy, `127.0.0.1:5555`) approves only the fixed platform hosts. MVP-SHORTCUT: the backend has no ask endpoint, so tenant custom domains are not possible; V1: V1-PL-10 sets `TLS_ASK_URL` to an app route |
| Customer-provided certificate | `files`: `tls /certs/fullchain.pem /certs/privkey.pem` | `certs/` mounted read-only; renewal is the customer's process (replace the files, `docker compose restart proxy`) |
| No public DNS (intranet) | `internal`: `tls internal` | Caddy's internal CA; export the root with `caddy trust`/`/data/caddy/pki/authorities/local/root.crt` and distribute via the customer's GPO/MDM |

Behind Cloudflare, use the origin certificate option and set `TRUSTED_PROXIES` accordingly.

## Single-tenant mode

On-prem installs normally run one tenant on one hostname:

```ini
PLATFORM_DOMAIN=helpdesk.corp.local
TENANCY_SINGLE_TENANT=corp        # every request runs in this tenant
HOST_LAYOUT=single                # SPA at /, API at /api, platform admin at /admin, consoles at /monitor
```

The workspace is created by `php artisan platform:create-tenant corp "Corp Ltd" --owner=it@corp.local` during the first deploy (Ansible runs it on the owner connection when `single_tenant` is set and keeps the printed invitation link in `/opt/smart-helpdesk/.tenant-created`). Single-host mode has one known gap: the proxy strips `/files`, which breaks presigned upload signatures (MVP-SHORTCUT in `single-tenant.env.example`; V1: V1-PL-15), and the invitation link printed by the command still names `app.<domain>` (open it on the single host instead).

## Mail

Two ways to send; the application code is the same ([email.md §As built (M3-18)](../04-domain/email.md#as-built-m3-18-identity-headers-mail-server)).

| Option | `.env` | When |
|---|---|---|
| **Bundled Stalwart** (default in the env examples) | `COMPOSE_PROFILES=…,mail`; `MAIL_MAILER=smtp`, `MAIL_HOST=mail`, `MAIL_PORT=587`, `MAIL_USERNAME=app@<domain>`, `MAIL_PASSWORD=<MAIL_APP_PASSWORD>`; `MAIL_ADMIN_PASSWORD`, `MAIL_APP_PASSWORD`, `MAIL_INBOUND_PASSWORD`; optional `MAIL_RELAY_*` | on-prem and any VM; inbound email (M3-19) needs it |
| External SMTP provider | no `mail` profile; `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` of the provider | outbound only, no inbound email |

`MAIL_MAILER=log` (the Ansible default while nothing is configured) never sends, so a half-configured install never spams. `MAIL_FROM_ADDRESS` must be on the mail domain (`no-reply@<domain>`): contact mail is sent from `support+<slug>@<domain>`, agent mail from `MAIL_FROM_ADDRESS`, both DKIM-signed for `<domain>`.

First start with the bundled server (Ansible does the same with `mail_profile: true`):

```sh
cd /opt/smart-helpdesk
docker compose up -d --wait                  # the mail profile is in COMPOSE_PROFILES
./infra/scripts/mail-init.sh                 # prints the DNS records and two MAIL_DKIM_* lines
# publish the records (§DNS below), add the two lines to .env, then
docker compose up -d app horizon scheduler   # Settings → Email shows the DKIM record as ready
docker compose exec app php artisan mail:send-test you@gmail.com --workspace=Demo
```

**Outbound port 25 blocked** (AWS, GCP, many VPS providers): set `MAIL_RELAY_HOST` and re-run `mail-init.sh`; Stalwart still signs with the platform's DKIM key and hands every remote message to the smart host. The SES steps are in [runbooks.md §Outbound mail](../11-operations/runbooks.md#outbound-mail-relay-port-25-blocked). Inbound mail needs port 25 **in**, which AWS does not block: the `envs/aws` security group already allows it, and the Ansible firewall role opens 25/tcp when `mail_profile` is true.

## Deploy and upgrade

Performed by `ansible-playbook -i inventory/<env>.ini deploy.yml -e app_version=<tag>` ([ansible.md](ansible.md)); the manual equivalent (verified on the rehearsal host, M3-14):

```sh
cd /opt/smart-helpdesk
helpdesk-backup pre-deploy                                            # dump + object archive in backups/
sed -i 's/^APP_VERSION=.*/APP_VERSION=2026.10.1/; s#^BACKEND_IMAGE=.*#BACKEND_IMAGE=ghcr.io/subham/smart-helpdesk-backend:2026.10.1#; s#^PROXY_IMAGE=.*#PROXY_IMAGE=ghcr.io/subham/smart-helpdesk-proxy:2026.10.1#' .env
docker compose pull app proxy                                         # or: gunzip -c images.tar.gz | docker load
docker compose exec app php artisan down --retry=30
docker compose run --rm migrate                                       # owner role (DB_CONNECTION=pgsql_owner)
docker compose exec horizon php artisan horizon:terminate             # workers finish their jobs and restart
docker compose up -d --wait --remove-orphans                          # recreates services on the new image
docker compose exec app php artisan up
./infra/scripts/smoke.sh                                              # with PLATFORM_DOMAIN, HOST_LAYOUT, SMOKE_CONNECT=127.0.0.1:443
```

The maintenance window is a few seconds for normal releases. Zero-downtime deploys are not an MVP target (NFR-OPS-06).

### Rollback

1. Set `APP_VERSION`, `BACKEND_IMAGE` and `PROXY_IMAGE` in `.env` back to the previous tag and `docker compose up -d --wait`.
2. If the release included a migration marked reversible in its release notes: `migrate:rollback --step=N` as the owner role before step 1.
3. If not reversible: restore the pre-deploy backup ([disaster-recovery.md](disaster-recovery.md)); `deploy.yml` takes a `pg_dump` immediately before migrating for this reason.

## Monitoring endpoints

| Endpoint | Auth | Used by |
|---|---|---|
| `GET /up` | none | Docker healthcheck (framework boot only) |
| `GET api.<domain>/v1/health` (JSON: database, Valkey, cache, queue, Horizon, scheduler heartbeat, SLA sweep, storage, disk) | `Authorization: Bearer <HEALTH_TOKEN>` | uptime monitor, customer's ops, `smoke.sh` when `HEALTH_TOKEN` is set |
| `/horizon` | platform admin | queue state, failed jobs |
| `/health` dashboard (HTML) | platform admin | human view |

Health failures notify `HEALTH_NOTIFY_MAIL` ([11-operations/observability.md](../11-operations/observability.md)).

## Resource limits and logs

Limits are in `prod.yaml` ([docker.md](docker.md)). Docker's `json-file` driver is capped at 10 MB × 3 files per container both in Compose and in `/etc/docker/daemon.json` (Ansible), so logs cannot fill the disk. `UsedDiskSpaceCheck` warns at 70 % and fails at 90 %.

## Security checklist (verified by the Ansible `firewall` role and the security tests)

- Only ports 22, 80, 443 open (ufw); SSH key-only, no root login.
- PostgreSQL, Valkey, RustFS, Reverb are on the `internal` network with no published ports; RustFS console never exposed.
- Containers run as `www-data` (`PUID`/`PGID`), never root; `app` connects as `helpdesk_app`.
- `.env` and `secrets/` are 0600/0700 and excluded from backups sent to third-party storage unless encrypted.
- `APP_DEBUG=false`, `TELESCOPE_ENABLED=false`, `LOG_LEVEL=info`.
- `APP_KEY`, `REDIS_PASSWORD`, DB passwords, S3 keys generated per install by Ansible.
- Unattended security upgrades for the OS; Docker images updated by releases.
- Backups verified weekly ([backups.md](backups.md)).

## Cloud versus on-prem

| Concern | On-prem / self-managed VPS | Cloud VM (any provider) |
|---|---|---|
| Host creation | customer VM, VPS or bare metal; inventory written by hand | provider console or optional OpenTofu module (DigitalOcean, AWS, GCP, Hetzner) |
| Object storage | RustFS/Garage container or customer S3 | RustFS on the VM or the provider's S3-compatible store (`AWS_USE_PATH_STYLE_ENDPOINT=false`) |
| DNS / TLS | intranet or single public host | A records for the eight `*.shp` hosts (or one wildcard A record), MX/SPF/DKIM/DMARC, PTR |
| Tenancy | single-tenant mode, single-host layout | multi-tenant workspaces on `app.shp…`, split hosts |
| Mail | bundled Stalwart; relay through the customer's SMTP if required | bundled Stalwart with a relay (SES, Postmark, provider SMTP) when port 25 is blocked, or a provider mailer |
| Backups | spatie backup to customer S3 + host dumps | spatie backup to a second bucket (any provider) + host dumps |
| Monitoring | customer's tooling hits `/health` | uptime monitor hits `/health` |
| Access | customer ops team | vendor via SSH key |

## DNS for `shp.subhambhandari.com.np`

| Record | Name | Value |
|---|---|---|
| A (and AAAA) | `shp`, `app.shp`, `api.shp`, `admin.shp`, `monitor.shp`, `docs.shp`, `files.shp`, `mail.shp` | VM address (or one `*.shp` wildcard A record plus `shp`) |
| MX | `shp` | `10 mail.shp.subhambhandari.com.np.` |
| TXT (SPF) | `shp` | `v=spf1 mx -all` (`MAIL_SPF_INCLUDE` adds the relay provider's `include:` when its envelope sender uses the domain) |
| TXT (DKIM) | `<selector>._domainkey.shp` | `v=DKIM1; k=rsa; h=sha256; p=<key>` printed by `mail-init.sh` (selector like `v1-rsa-20260921`); with SES also its three `_domainkey` CNAMEs |
| TXT (DMARC) | `_dmarc.shp` | `v=DMARC1; p=none; rua=mailto:postmaster@shp.subhambhandari.com.np` (`MAIL_DMARC_POLICY`; tighten to `quarantine` after checking reports) |
| PTR | VM address | `mail.shp.subhambhandari.com.np` (set at the hosting provider) |
| CAA (optional) | `shp` | `0 issue "letsencrypt.org"` |


## Smoke test

`infra/scripts/smoke.sh` (`just smoke` in development, `just prod-smoke <domain> [single]` against a server) checks every host of the layout: landing page, `/up`, `/v1/ping`, runtime `config.json`, the SPA deep link, admin and docs, `401` from `/v1/me` without a session, the proxy security headers, RustFS health and the bucket CORS preflight, and basic auth in front of `monitor`. Optional: `HEALTH_TOKEN` adds `/v1/health`; `SMOKE_EMAIL`/`SMOKE_PASSWORD` sign in through the SPA flow (CSRF cookie, login, `/v1/me`). `SMOKE_CONNECT=<ip>:<port>` connects to an address without changing Host or SNI, which tests a VM before DNS exists or a stack on another port:

```sh
PLATFORM_DOMAIN=shp.subhambhandari.com.np SMOKE_DEV=0 HEALTH_TOKEN=… ./infra/scripts/smoke.sh
PLATFORM_DOMAIN=helpdesk.corp.local HOST_LAYOUT=single SMOKE_WORKSPACE=corp SMOKE_DEV=0 SMOKE_INSECURE=1 ./infra/scripts/smoke.sh
PLATFORM_DOMAIN=shp.subhambhandari.com.np SMOKE_CONNECT=203.0.113.10:443 SMOKE_INSECURE=1 SMOKE_DEV=0 ./infra/scripts/smoke.sh   # before DNS
```

## As built and verified (M3-14, 2026-09-21)

| Check | How | Result |
|---|---|---|
| Production overlay on this workstation | `docker compose -p shp-prodtest` with `compose.yaml` + `infra/compose/prod.yaml`, images built from the `production` targets, `.env` from `multi-tenant.env.example`, `TLS_MODE=internal`, ports 18080/18443 | all services healthy; only the proxy publishes ports; migrations as `helpdesk_owner`; workspace, owner invitation accepted through the API, platform admin; `smoke.sh` with sign-in passes |
| Ansible, single-tenant | `site.yml` against a disposable Debian 13 container with systemd (privileged, nested Docker), `host_layout=single`, `tls_mode=internal`, images from an archive | first run 2 min 18 s, all green; second run `changed=0`; `smoke.sh` (single layout, sign-in as the invited owner) passes |
| Ansible, multi-tenant | same host re-run with `host_layout=split` | `.env` re-rendered, stack converged, split-layout `smoke.sh` with sign-in passes |
| Deploy | `deploy.yml` on the same host | pre-deploy dump, maintenance mode, migrate, Horizon restart, smoke: green |
| TLS variants | `caddy validate` of both Caddyfiles in `acme`, `on_demand`, `internal`, `files`; ask endpoint answers 200 for the eight platform hosts and 403 otherwise | valid |

`/v1/health` returned 503 in every rehearsal for two reasons that are not deployment faults: the workstation disk was 91–94 % full (`UsedDiskSpaceCheck` fails at 90 %), and `SlaSweepCheck` reports "has not run yet" although the sweep runs every minute, because the Valkey cache returns the heartbeat timestamp as a numeric string and the check requires an integer (reported to the backend track; the same happens on the development stack).

Not done on this machine (no cloud credentials, no reachable VM): a public VM with real DNS and ACME certificates, and `tofu apply`. The commands for the owner are in [ansible.md](ansible.md#first-deployment-to-a-real-vm) and [terraform.md](terraform.md#commands).
