# Production deployment (single server)

Applies to on-premise servers and the reference cloud VM alike; the differences are configuration ([deployment architecture](../03-architecture/deployment.md), [ADR-0012](../adr/0012-deployment-architecture.md)). Hosts are prepared by [Ansible](ansible.md); cloud VMs are created by [OpenTofu](terraform.md) first.

## Sizing

| Tier | vCPU | RAM | Disk | Fits |
|---|---|---|---|---|
| Minimum | 2 | 4 GB | 40 GB SSD | ≤ 20 agents, ≤ 100k tickets, RustFS on the same host |
| Comfortable | 4 | 8 GB | 80 GB SSD | ≤ 100 agents, Reverb enabled |

Memory budget at the minimum tier: PostgreSQL 1 GB, app 768 MB, Horizon 768 MB, scheduler 256 MB, proxy 256 MB, Valkey 512 MB, RustFS 256 MB, OS ≈ 200 MB. Swap of 2 GB is configured by the `common` role as a safety margin.

## Host layout

```text
/opt/smart-helpdesk/
├── compose.yaml                  # copied from the release
├── infra/compose/{base,dev,prod,demo}.yaml
├── infra/caddy/Caddyfile
├── infra/postgres/init/
├── .env                          # 0600 deploy:deploy, rendered by Ansible
├── secrets/                      # 0700; db_owner_password, db_app_password
├── certs/                        # optional customer certificate (fullchain.pem, privkey.pem)
└── backups/                      # host pg_dump safety net (7 days)
/var/lib/docker/volumes/smart-helpdesk_{pg-data,valkey-data,object-data,caddy-data,...}
```

The `deploy` user owns the tree and is in the `docker` group; nothing else is installed on the host but Docker.

## Profiles per deployment

| Profile | On-prem | Cloud | Purpose |
|---|---|---|---|
| (none) | always | always | proxy, app, horizon, scheduler, postgres, valkey |
| `storage` | default on | off (managed Spaces/R2) | RustFS container; omit if the customer supplies an S3 endpoint |
| `realtime` | optional | optional | Reverb; requires `REALTIME_ENABLED=true` and `BROADCAST_CONNECTION=reverb` |
| `demo` | never | never | demo fixtures |

Set `COMPOSE_PROFILES=storage,realtime` in `.env`; Ansible renders it from inventory variables.

## TLS options

| Situation | Caddyfile `tls` | Notes |
|---|---|---|
| Public DNS, SaaS | one ACME certificate per fixed host (`shp`, `app`, `api`, `admin`, `monitor`, `docs`, `files`, `mail` under `subhambhandari.com.np`) | HTTP-01; no wildcard or on-demand TLS; certificates persist in the `caddy-data` volume; ports 80/443 must be reachable from the internet |
| Public DNS, single on-prem host | default (automatic HTTPS for the one hostname) | HTTP-01; no wildcard needed |
| Customer-provided certificate | `tls /certs/fullchain.pem /certs/privkey.pem` | mounted read-only; renewal is the customer's process |
| No public DNS (intranet) | `tls internal` | Caddy's internal CA; export the root with `caddy trust`/`/data/caddy/pki/authorities/local/root.crt` and distribute via the customer's GPO/MDM |

Behind Cloudflare, use the origin certificate option and set `TRUSTED_PROXIES` accordingly.

## Single-tenant mode

On-prem installs normally run one tenant on one hostname:

```ini
PLATFORM_DOMAIN=helpdesk.corp.local
TENANCY_SINGLE_TENANT=corp        # every request runs in this tenant
HOST_LAYOUT=single                # SPA at /, API at /api, platform admin at /admin, consoles at /monitor
```

The tenant is created by `php artisan tenants:create corp --name="Corp Ltd" --owner=it@corp.local` during first deploy (Ansible runs it when `single_tenant` is set).

## Mail

Only SMTP is configured for on-prem: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`. The shipped default is `MAIL_MAILER=log` so a half-configured install never spams. Cloud installs may use `postmark`, `ses` or `resend` via the same variables plus the provider key. Test with `php artisan mail:test admin@example.com`.

## Deploy and upgrade

Performed by `ansible-playbook deploy.yml -e app_version=<tag>` ([ansible.md](ansible.md)); the manual equivalent:

```sh
cd /opt/smart-helpdesk
export APP_VERSION=2026.10.1
docker compose pull
docker compose run --rm app php artisan down --secret="$(openssl rand -hex 8)" --retry=30
docker compose run --rm -e DB_USERNAME=helpdesk_owner -e DB_PASSWORD_FILE=/run/secrets/db_owner_password app php artisan migrate --force
docker compose exec horizon php artisan horizon:terminate     # workers drain and restart on new code
docker compose up -d --wait --remove-orphans                    # recreates app/scheduler/proxy on the new image
docker compose exec app php artisan up
docker compose exec app php artisan health:check
```

The maintenance window is a few seconds for normal releases. Zero-downtime deploys are not an MVP target (NFR-OPS-06).

### Rollback

1. `export APP_VERSION=<previous>` and `docker compose up -d --wait`.
2. If the release included a migration marked reversible in its release notes: `migrate:rollback --step=N` as the owner role before step 1.
3. If not reversible: restore the pre-deploy backup ([disaster-recovery.md](disaster-recovery.md)); `deploy.yml` takes a `pg_dump` immediately before migrating for this reason.

## Monitoring endpoints

| Endpoint | Auth | Used by |
|---|---|---|
| `GET /up` | none | Docker healthcheck (framework boot only) |
| `GET /health` (JSON: database, Valkey, cache, queue, scheduler heartbeat, storage, disk, backups) | `HEALTH_TOKEN` header or platform admin session | uptime monitor, customer's ops |
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
| TXT (SPF) | `shp` | `v=spf1 mx -all` (add `include:` of the relay provider when relaying) |
| TXT (DKIM) | `<selector>._domainkey.shp` | public key printed by `mail-init.sh` |
| TXT (DMARC) | `_dmarc.shp` | `v=DMARC1; p=none; rua=mailto:dmarc@shp.subhambhandari.com.np` (tighten to `quarantine` after checking reports) |
| PTR | VM address | `mail.shp.subhambhandari.com.np` (set at the hosting provider) |
| CAA (optional) | `shp` | `0 issue "letsencrypt.org"` |

