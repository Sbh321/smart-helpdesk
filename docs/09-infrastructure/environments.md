# Environments

Everything that runs in the two environments: every host, every service, every data store, and
every credential. The local environment is the development stack from
[local-development.md](local-development.md). The AWS environment is the live instance described in
[terraform.md §AWS environment](terraform.md#aws-environment-deployed-2026-09-21) and
[production.md](production.md).

**Credentials rule.** This page names each credential and where it is kept; it never contains a real
secret. The only values written here are development defaults that are already in the repository
(`.env.example`, `backend/.env.example`, the demo seeder). Read a secret from its file when you need it,
and never paste it into the repository, a commit, an issue or a chat.

## At a glance

| | Local | AWS |
|---|---|---|
| Platform domain | `shp.localhost` (resolves to 127.0.0.1 by itself, RFC 6761) | `shp.subhambhandari.com.np` (Cloudflare DNS, A records, DNS only) |
| Machine | your laptop, Docker Compose | EC2 `t3.small` (2 vCPU, 2 GB + 4 GB swap), Debian 13, `ap-south-1` (Mumbai), instance `i-002dcd3d9270b0607`, Elastic IP `3.7.49.184` |
| Compose files | `compose.yaml` + `compose.override.yaml` (development) | `compose.yaml` + `infra/compose/prod.yaml`, in `/opt/smart-helpdesk` |
| Compose profiles | `dev,storage` (root `.env`); `mail`, `realtime`, `demo` on request | `storage,mail` |
| Images | built locally (`smart-helpdesk-backend:dev`, `smart-helpdesk-proxy:local`) | `ghcr.io/sbh321/smart-helpdesk-{backend,proxy}:sha-<commit>` (public), the tag set by `app_version` |
| TLS | Caddy internal CA (`tls internal`); trust its root once | Let's Encrypt, obtained by Caddy on the VM (`tls_mode=acme`) |
| `APP_ENV` | `local` (Telescope on, open health endpoint) | `production` (Telescope off, `APP_DEBUG=false`) |
| Workspaces | `acme`, `globex` (demo dataset, `just demo-reset`) | `demo` |
| Outgoing mail | Mailpit catches everything; nothing leaves the laptop | Stalwart on the VM → Brevo relay (`smtp-relay.brevo.com:587`); Amazon SES is prepared, awaiting production access |
| Realtime (Reverb) | off (profile `realtime` to try it) | off (`realtime_profile: false`); the SPA polls |
| Backups | none | daily 02:30 host timer → `/opt/smart-helpdesk/backups`, kept 7 days |
| Set up by | `just setup` | OpenTofu (`infra/tofu/envs/aws`) + Ansible (`infra/ansible/site.yml`, `deploy.yml`) |

## Hosts and URLs

Every host is served by the one `proxy` container (Caddy, `frontend/docker/Caddyfile`). Tenants are
workspaces in the URL path, never hosts ([ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md)).

| Host | Local URL | AWS URL | Serves | Access |
|---|---|---|---|---|
| apex | https://shp.localhost | https://shp.subhambhandari.com.np | landing site (prerendered, M5-04); `/go/app`, `/go/find` (workspace finder) and `/go/docs` redirect | public |
| `app` | https://app.shp.localhost/acme | https://app.shp.subhambhandari.com.np/demo | tenant SPA (agents, managers, admins); `/config.json` runtime config | workspace sign-in |
| `api` | https://api.shp.localhost/v1 | https://api.shp.subhambhandari.com.np/v1 | Laravel REST API (`/v1/ping`, `/v1/health`, `/oauth/token`); `/app/*` is the Reverb socket when realtime is on | session cookie (SPA) or OAuth 2.0 bearer token (API clients) |
| `admin` | https://admin.shp.localhost | https://admin.shp.subhambhandari.com.np | platform console SPA; `/platform-api/*` goes to the backend | platform super admin sign-in |
| `monitor` | https://monitor.shp.localhost | https://monitor.shp.subhambhandari.com.np | `/horizon` (queues), `/health`, `/telescope` (local only), `/storage` → RustFS console, `/mail/*` → Stalwart admin API | platform super admin only: the console's *Monitoring* button hands over a platform pass (ADR-0024); anyone else is sent to the console sign-in |
| `docs` | https://docs.shp.localhost | https://docs.shp.subhambhandari.com.np | API reference (Scramble UI) and `/openapi.json` | public |
| `files` | https://files.shp.localhost | https://files.shp.subhambhandari.com.np | S3 endpoint (RustFS) for presigned upload and download URLs | presigned URLs only |
| `platform-docs` | https://platform-docs.shp.localhost | https://platform-docs.shp.subhambhandari.com.np | the platform documentation (`docs/` as a site, M5-05) | platform super admin: the console's *Platform docs* button hands over (ADR-0024); anyone else is sent to the console sign-in |
| `mail` | https://mail.shp.localhost | https://mail.shp.subhambhandari.com.np | local: Mailpit inbox; AWS: Stalwart web admin | local: open; AWS: Stalwart `admin` login |

Local-only extras:

| URL | What |
|---|---|
| http://localhost:5173 | Vite dev server (`pnpm -C frontend dev`), shown through `app.shp.localhost` when `VITE_DEV=1` |
| http://localhost:9100 | webhook-echo, which verifies and prints signed webhook deliveries (profiles `dev`, `demo`) |
| `127.0.0.1:55439` | PostgreSQL for database tools (`DEV_DB_PORT`) |
| `127.0.0.1:56379` | Valkey (`DEV_VALKEY_PORT`) |
| `http://localhost:8081` | HTTP, redirect to HTTPS only (`HTTP_PORT`) |

AWS ports open to the internet: 80 and 443 (proxy), 25 (inbound mail to Stalwart), 22 (SSH, allowed by
the security group only from `ssh_cidrs`, your address). Nothing else is published; PostgreSQL, Valkey,
RustFS and the mail submission and IMAP ports stay on the internal Docker network.

DNS on AWS (Cloudflare zone `subhambhandari.com.np`): A records for the apex and the eight hosts
above → `3.7.49.184`; MX → `mail.shp.subhambhandari.com.np`; SPF `v=spf1 mx -all`; DMARC; Stalwart's
DKIM TXT (selector `v1-rsa-20260922`); the SES DKIM CNAMEs and the `bounce.` MAIL FROM records
(kept for switching back to SES). Records: `infra/tofu/providers/cloudflare/dns`, `infra/tofu/envs/aws/mail.tf`.

## Services (containers)

| Service | Image | Role | Local | AWS |
|---|---|---|---|---|
| `proxy` | `smart-helpdesk-proxy` (Caddy 2.11 + built SPAs) | TLS, every host above, static tenant and admin SPAs | ✓ | ✓ (256 MB) |
| `app` | `smart-helpdesk-backend` (PHP 8.5, Laravel 13) | HTTP API | ✓ (code bind-mounted) | ✓ (768 MB) |
| `horizon` | same image, `artisan horizon` | queue workers: default, sla, notifications, reports, media, webhooks, broadcasts | ✓ | ✓ (fixed pool of 2, 1 for webhooks) |
| `scheduler` | same image, `artisan schedule:work` | SLA sweep, priority re-evaluation, inbound mail fetch, webhook retries, health heartbeats | ✓ | ✓ |
| `reverb` | same image, `artisan reverb:start` | WebSocket server (profile `realtime`) | off | off |
| `migrate` | same image | one-shot migrations as the owner role (profile `ops`) | via `just migrate` | run by every deploy |
| `postgres` | `postgres:18-trixie` | database, row-level security | ✓ | ✓ (1 GB) |
| `valkey` | `valkey/valkey:9-alpine` | cache, queues, rate limits, health results | ✓ | ✓ (password set) |
| `rustfs` | `rustfs/rustfs:1.0.0` | S3-compatible object storage, bucket `helpdesk` (profile `storage`) | ✓ (console on) | ✓ (console off) |
| `mail` | `stalwartlabs/stalwart:v0.16.22-alpine` | mail server: submission for the app, inbound on 25, catch-all, DKIM, relay (profile `mail`) | off unless `--profile mail` | ✓ (512 MB) |
| `mail-cli` | `stalwartlabs/cli:1.0.12` | Stalwart management CLI for `mail-init.sh` (never started by `up`) | on demand | on demand |
| `mailpit` | `ghcr.io/axllent/mailpit:v1.31.1` | catches all outgoing mail (profile `dev`) | ✓ | — |
| `webhook-echo` | `node:24-alpine` + `tools/webhook-echo` | test receiver for webhooks (profiles `dev`, `demo`) | ✓ | — |

## Data stores

| Store | Local | AWS |
|---|---|---|
| PostgreSQL databases | `helpdesk` (dev), `helpdesk_test` (tests; only one test run at a time) | `helpdesk` |
| PostgreSQL roles | `postgres` (superuser), `helpdesk_owner` (owns tables, runs migrations), `helpdesk_app` (runtime, `NOBYPASSRLS`), `helpdesk_backup` (read-only, `BYPASSRLS`, for `pg_dump`) | same four |
| Valkey | no password, prefix `shp_` | password `VALKEY_PASSWORD`, prefix `shp_` |
| Object storage | RustFS bucket `helpdesk`, endpoint `http://rustfs:9000`, public via `files.` | same, on the VM's disk (`object-data` volume) |
| Docker volumes | `pg-data`, `valkey-data`, `object-data`, `caddy-data`, `caddy-config`, `mail-data` | same, under `/var/lib/docker` on the 30 GB encrypted gp3 disk |
| Backups | — | `/opt/smart-helpdesk/backups` (database dump + RustFS archive, 7 days); an off-host copy with `ansible-playbook -i inventory/aws.ini backup.yml -e fetch_backup=true` → `infra/ansible/backups/` |

## Mail addresses (AWS)

| Address | Purpose |
|---|---|
| `support+<workspace>@shp.subhambhandari.com.np` (for the demo workspace: `support+demo@…`) | customers write here; a new ticket in that workspace |
| `ticket+…@shp.subhambhandari.com.np` | reply-to address on notifications; a reply becomes a comment |
| `inbound@shp.subhambhandari.com.np` | Stalwart mailbox that receives the catch-all; `mail:fetch-inbound` reads it over IMAP every minute |
| `app@shp.subhambhandari.com.np` | account the application uses to submit mail to Stalwart (port 587, internal) |
| `no-reply@shp.subhambhandari.com.np` | `From` address of notifications |
| `admin@shp.subhambhandari.com.np` | platform super admin and demo workspace owner; its mail lands in the `inbound` mailbox |

Locally all outgoing mail stops in Mailpit (`mail.shp.localhost`). Inbound mail can be simulated with the
`mail` profile (`just mail-inject`, `just mail-reply`; see local-development.md).

## Credentials

### Local

The values below are development defaults from the repository; they are only valid on your laptop.

| System | User | Password or key | Where it is set |
|---|---|---|---|
| Tenant SPA, workspace `acme` | `meera@acme.test` (Owner), `priya@acme.test` (Manager), `dev@acme.test` (Developer), agents `arjun@`, `asha@`, `bikram@`, `chen@`, `deepa@`, `elena@`, `farid@`, `grace@acme.test` | `password` | `DEMO_PASSWORD` in `backend/.env` (unset = `password`) |
| Tenant SPA, workspace `globex` | `sam@globex.test` (Admin), `lina@globex.test` | `password` | same |
| Platform console (`admin.shp.localhost`) | `admin@platform.test` | `password` (the demo password, outside production) | demo seeder |
| Demo OAuth API client | client id and secret | printed by `just demo-reset` | database |
| Demo webhook subscriptions | signing secret | `DEMO_WEBHOOK_SECRET`, also webhook-echo's default | `backend/.env`, `infra/compose/tools.yaml` |
| PostgreSQL `postgres`, `helpdesk_owner`, `helpdesk_app`, `helpdesk_backup` | role name | generated by `just setup` | `secrets/db_superuser_password`, `db_owner_password`, `db_app_password`, `db_backup_password` (mode 600, gitignored) |
| RustFS (console at `monitor.shp.localhost/storage`, S3 API) | `helpdesk` | `helpdesk-secret` | `STORAGE_ACCESS_KEY` / `STORAGE_SECRET_KEY` in `.env`; `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` in `backend/.env` |
| Stalwart admin (profile `mail`) | `admin` | `dev-mail-admin` | `MAIL_ADMIN_PASSWORD` in `.env` |
| Stalwart `app` and `inbound` accounts (profile `mail`) | `app@`, `inbound@shp.localhost` | `MAIL_APP_PASSWORD`, `MAIL_INBOUND_PASSWORD` | `backend/.env` |
| Mailpit | no login locally | none | `infra/compose/tools.yaml` |
| Horizon, Telescope, health, storage console (`monitor.`) | `admin@platform.test` in the console | `password`; the console's *Monitoring* button hands over the pass | demo seeder |
| Valkey | — | none | `VALKEY_PASSWORD` unset |
| Laravel `APP_KEY`, Passport keys | — | generated by `just setup` | `backend/.env`, `backend/storage/oauth-*.key` |
| Caddy local CA | — | root certificate to trust in the browser | `docker compose cp proxy:/data/caddy/pki/authorities/local/root.crt .` (a copy is in `~/.config/smart-helpdesk/caddy-root.crt`) |

### AWS: application

Every secret the server uses was generated by Ansible on the first install and is kept in two places:
the source on your machine, `infra/ansible/host_vars/smart-helpdesk.secrets.yml` (gitignored,
**plain text**, so keep the laptop's disk encrypted), and the rendered `/opt/smart-helpdesk/.env` and
`/opt/smart-helpdesk/secrets/` on the VM (mode 600). Read a value with, for example,
`grep '^monitor_password:' infra/ansible/host_vars/smart-helpdesk.secrets.yml`.

| System | User | Secret (key in `smart-helpdesk.secrets.yml` unless stated) | Used by |
|---|---|---|---|
| Platform console (`admin.`) | `admin@shp.subhambhandari.com.np` | `platform_admin_password` | you |
| Platform docs (`platform-docs.`) | the platform admin | no password of its own: the console hands over a pass (cookie `shp_platform_pass`, eight hours, ended by console sign-out) | you |
| Demo workspace owner (`app.…/demo`) | `admin@shp.subhambhandari.com.np` | `~/.config/smart-helpdesk/demo-owner-password` (the invitation link used to set it: `demo-owner-invitation.txt` in the same folder) | you |
| Monitoring (`monitor.`: Horizon, health, storage console, Stalwart API) | the platform admin | no password of its own: the console's *Monitoring* button hands over a pass (cookie `shp_platform_pass`, eight hours, ended by console sign-out). `monitor_password` in the secrets file is no longer used | you |
| Health endpoint `api.…/v1/health` | — | `health_token`, sent as `Authorization: Bearer …` | uptime checks |
| Stalwart admin (`mail.`, `monitor.…/mail`) | `admin` | `mail_admin_password` | you, `mail-init.sh` |
| Stalwart `app@` submission account | `app@shp.subhambhandari.com.np` | `mail_app_password` | Laravel |
| Stalwart `inbound@` mailbox (IMAP) | `inbound@shp.subhambhandari.com.np` | `mail_inbound_password` | `mail:fetch-inbound` |
| PostgreSQL | `postgres`, `helpdesk_owner`, `helpdesk_app`, `helpdesk_backup` | `db_superuser_password`, `db_owner_password`, `db_app_password`, `db_backup_password` | containers (Docker secrets) |
| Valkey | — | `valkey_password` | backend |
| RustFS / S3 | access key | `s3_key` / `s3_secret` | backend, storage console |
| Reverb | app id `smart-helpdesk` | `reverb_app_key` (public), `reverb_app_secret` | unused while realtime is off |
| Laravel encryption key | — | `app_key` (**never rotate casually**: it decrypts webhook secrets and other encrypted columns) | backend |
| Passport signing keys | — | `/opt/smart-helpdesk/secrets/oauth-private.key`, `oauth-public.key` on the VM | OAuth client-credentials tokens |
| Tenant API clients and webhook secrets | per client or subscription | shown once in Settings → API clients / Webhooks; stored hashed or encrypted | integrators |

### AWS: infrastructure and outside accounts

| System | Credential | Where |
|---|---|---|
| SSH to the VM | user `deploy`, your SSH key; the security group allows only `ssh_cidrs` | `ssh deploy@3.7.49.184`; key list in `infra/tofu/envs/aws/terraform.tfvars` (gitignored) |
| Ansible inventory | host `smart-helpdesk` → `3.7.49.184` | `infra/ansible/inventory/aws.ini` (written by OpenTofu, gitignored) |
| Deploy settings | image tag (`app_version`), admin e-mail, swap, Horizon pool, mail relay settings, DKIM selector | `~/.config/smart-helpdesk/aws-vars.yml` |
| Brevo SMTP relay (current outbound mail) | login `mail_relay_username`, key `mail_relay_password` | `aws-vars.yml`; the key also in `~/.config/smart-helpdesk/brevo-smtp-key` |
| Amazon SES (prepared, not in use) | send-only IAM user; SMTP password derived by OpenTofu | `tofu output` in `infra/tofu/envs/aws` |
| AWS account (Free plan, credits until 2027-02-28) | your AWS CLI session (`aws login`) | AWS console; region `ap-south-1`. The same account runs other projects (ccp-demo, final-project-app): never change them |
| OpenTofu state | encrypted state file and its passphrase | `~/.config/smart-helpdesk/tofu/aws.tfstate`, `~/.config/smart-helpdesk/tofu-state-passphrase` (`TF_VAR_state_passphrase`) |
| Cloudflare DNS | API token (Zone → DNS → Edit), shared with the ccp project | `~/.config/ccp/cloudflare.env` (`CLOUDFLARE_API_TOKEN`) |
| GitHub and GHCR | CI builds and pushes images on every push to `main`; the packages are public, so the VM pulls without a login | GitHub account `sbh321` |
| Let's Encrypt | none; Caddy renews certificates by itself | `caddy-data` volume on the VM |

## Everyday operations (AWS)

```sh
ssh deploy@3.7.49.184
cd /opt/smart-helpdesk                          # .env, secrets/, backups/, compose files
docker compose ps                               # COMPOSE_FILE in .env selects the production files
docker compose logs -f app horizon scheduler
docker compose exec postgres psql -U postgres helpdesk
docker compose exec app php artisan tenants:list

# a new release, from your machine, after CI has pushed sha-<commit>:
#   set app_version: sha-<commit> in ~/.config/smart-helpdesk/aws-vars.yml, then
cd infra/ansible && ansible-playbook -i inventory/aws.ini deploy.yml -e @~/.config/smart-helpdesk/aws-vars.yml
```

When a credential changes, change it in `smart-helpdesk.secrets.yml` (or `aws-vars.yml`) and run
`site.yml`, so the file on your machine and the server stay the same. Mail relay changes and the SES
switch-back: [runbooks.md §Outbound mail](../11-operations/runbooks.md#outbound-mail-relay-port-25-blocked).
