# Docker architecture

Decision: [ADR-0012](../adr/0012-deployment-architecture.md). Versions: [01-research/versions.md](../01-research/versions.md). One backend image runs `app`, `horizon`, `scheduler` and `reverb`; one proxy image serves the SPA and terminates TLS. The same images run on a workstation, an on-prem server and the cloud VM; only Compose profiles and environment differ.

## Images

### Backend (`backend/Dockerfile`)

Base: `serversideup/php:8.5-fpm-nginx` (pinned to a patch tag such as `8.5.10-fpm-nginx-trixie`). It ships nginx + PHP-FPM under S6 Overlay, a healthcheck driven by `HEALTHCHECK_PATH`, `PUID`/`PGID` support, production `php.ini` defaults and `install-php-extensions`.

```dockerfile
# syntax=docker/dockerfile:1.7
ARG PHP_IMAGE=serversideup/php:8.5.10-fpm-nginx-trixie

# ---- 1. vendor: composer install without dev deps, cached ----
FROM ${PHP_IMAGE} AS vendor
USER root
RUN install-php-extensions pdo_pgsql redis intl gd bcmath pcntl zip sockets uv
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- 2. runtime ----
FROM ${PHP_IMAGE} AS runtime
USER root
RUN install-php-extensions pdo_pgsql redis intl gd bcmath pcntl zip sockets uv \
 && apt-get update && apt-get install -y --no-install-recommends postgresql-client-18 \
 && rm -rf /var/lib/apt/lists/*
ENV HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    AUTORUN_ENABLED=false \
    LOG_CHANNEL=stderr
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
RUN composer dump-autoload --optimize --classmap-authoritative \
 && php artisan storage:link || true
USER www-data
# default CMD from the base image starts nginx + php-fpm; other services override `command`
```

Notes:

- `uv` is `ext-uv` for Reverb's event loop; `sockets` is required by Reverb; `pcntl` by Horizon signal handling; `postgresql-client-18` by `spatie/laravel-backup` and the restore runbook (client major must match the server).
- `AUTORUN_ENABLED=false`: migrations run explicitly in the deploy step, not on every container boot (several containers share the image).
- Dev builds use the same Dockerfile with a bind mount over `/var/www/html` and `PHP_OPCACHE_VALIDATE_TIMESTAMPS=1`.

### Frontend and edge (`frontend/Dockerfile`)

```dockerfile
# syntax=docker/dockerfile:1.7
FROM node:24-alpine AS build
RUN corepack enable
WORKDIR /app
COPY package.json pnpm-lock.yaml ./
RUN --mount=type=cache,id=pnpm,target=/root/.local/share/pnpm/store pnpm install --frozen-lockfile
COPY . .
RUN pnpm build              # -> /app/dist, no VITE_* env baked in

FROM caddy:2.11-alpine AS runtime
COPY --from=build /app/dist /srv
COPY --from=build /app/docker/config.template.json /srv/config.template.json
COPY infra/caddy/Caddyfile /etc/caddy/Caddyfile
COPY infra/caddy/entrypoint.sh /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
```

`entrypoint.sh` renders runtime configuration once at container start so one image works on any hostname:

```sh
#!/bin/sh
set -eu
envsubst '$PLATFORM_DOMAIN $REALTIME_ENABLED $REVERB_APP_KEY $REALTIME_HOST $REALTIME_PATH $STORAGE_PUBLIC_ENDPOINT' \
  < /srv/config.template.json > /srv/config.json
exec "$@"
```

`/srv/config.json` is what the SPA fetches at boot ([03-architecture/frontend.md](../03-architecture/frontend.md) §Runtime configuration). Nothing environment-specific is baked at build time.

### Caddyfile (proxy)

Host layout per [ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md). `{$PLATFORM_DOMAIN}` is `shp.subhambhandari.com.np` in production and `shp.localhost` in development. As built, `TLS_MODE` selects a snippet: `acme` (automatic ACME per host), `on_demand` (certificate at first handshake, approved by a loopback ask endpoint that allows only the fixed hosts), `internal` (development and intranets) or `files` (`/certs/fullchain.pem /certs/privkey.pem`).

```caddyfile
{
	servers { protocols h1 h2 h3 }
}

(common) {
	encode zstd gzip
	log { output stdout format json }
	header {
		Strict-Transport-Security "max-age=31536000"
		X-Content-Type-Options nosniff
		Referrer-Policy strict-origin-when-cross-origin
		-Server
	}
}

(tls_mode) {
	tls {$TLS}
}

# landing page
{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	root * /srv/landing
	file_server
}

# tenant SPA (workspace in the path)
app.{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	header X-Frame-Options DENY
	header Content-Security-Policy "{$CSP_APP}"
	root * /srv/app
	try_files {path} /index.html
	file_server
}

# API, OAuth, Sanctum, broadcasting auth, websockets
api.{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	@realtime path /app/*            # the Reverb socket only; /apps (publishing) stays internal (M3-16)
	handle @realtime { reverse_proxy reverb:8080 }
	handle { reverse_proxy app:8080 }
}

# platform admin SPA + same-origin platform API
admin.{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	header X-Frame-Options DENY
	handle /platform-api/* { reverse_proxy app:8080 }
	handle {
		root * /srv/app
		try_files {path} /index.html
		file_server
	}
}

# operations consoles (platform admins only; app gate + basic auth)
monitor.{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	basic_auth { {$MONITOR_USER} {$MONITOR_PASSWORD_HASH} }
	handle /mail/* { uri strip_prefix /mail
		reverse_proxy mail:8080 }
	redir /storage /rustfs/console/        # the RustFS console uses absolute /rustfs/... paths
	handle /rustfs/* { reverse_proxy rustfs:9001 }
	handle { reverse_proxy app:8080 }      # /horizon, /health, /log-viewer, /telescope (dev)
}

# public API reference
docs.{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	reverse_proxy app:8080 { rewrite /docs{uri} }
}

# S3 endpoint for presigned URLs
files.{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	reverse_proxy rustfs:9000
}

# mail server web endpoints (autoconfig, MTA-STS); SMTP/IMAP ports are published by the mail container
mail.{$PLATFORM_DOMAIN} {
	import common
	import tls_mode
	reverse_proxy mail:8080
}
```

In development `mail.shp.localhost` points at the Mailpit UI instead. **Single-host mode** (`HOST_LAYOUT=single`, on-prem) uses one site block: `/api/*` → `app:8080` with the `/api` prefix stripped (so the application always serves `/v1`), `/platform-api/*` and `/docs/*` → `app:8080`, `/monitor/*` → the consoles, `/files/*` → RustFS, everything else → the SPA.

## Compose architecture

As built in M1-04. The files below are authoritative; the YAML excerpts further down show the design intent and may differ in detail.

```text
compose.yaml                 # every service; dev defaults are off; includes infra/compose/tools.yaml
compose.override.yaml        # dev only, loaded automatically: bind mounts, dev image target, 127.0.0.1 ports, TLS internal
infra/compose/tools.yaml     # profile dev: mailpit; dev and demo: webhook-echo
infra/compose/prod.yaml      # production overlay (M3-14): images by tag, restart, limits, log rotation, migrate one-shot, Passport keys, certs
```

Plain `docker compose up` in the repository loads `compose.yaml` and `compose.override.yaml`. Servers never have the override file; their `.env` sets `COMPOSE_FILE=compose.yaml:infra/compose/prod.yaml` and `BACKEND_ENV_FILE=.env`, so `docker compose up -d --wait` there means the production stack (templates in `infra/env/`, details in [production.md](production.md#host-layout)). Migrations run with `docker compose run --rm migrate` (profile `ops`, `DB_CONNECTION=pgsql_owner`). The first design used `include` overlays, but `include` cannot override services that the root file defines, so the standard override file replaced it.

Profiles: `dev` (mailpit), `storage` (rustfs), `realtime` (reverb), `mail` (Stalwart, M3-18; `mail-tools` holds its CLI for `mail-init.sh`), `demo` (webhook-echo). There is no Playwright container: the E2E suite runs on the host or the CI runner with `pnpm -C frontend e2e` (M3-12, [testing.md](../10-quality/testing.md) §End-to-end), which keeps the multi-gigabyte browser image off the machine. The root `.env` sets `COMPOSE_PROFILES=dev,storage` for development. Production on-prem typically runs `COMPOSE_PROFILES=storage`, cloud runs no profile (managed storage), and both add `realtime` when enabled.

Image sources are variables (`POSTGRES_IMAGE`, `VALKEY_IMAGE`, `MAILPIT_IMAGE`, `NODE_IMAGE`, `CADDY_IMAGE`), so a host that hits the Docker Hub pull limit can use mirrors such as `public.ecr.aws/docker/library/postgres:18-trixie`, `public.ecr.aws/valkey/valkey:9-alpine` and `ghcr.io/axllent/mailpit:v1.31.1`.

### Networks and port rules

| Network | Members | Published ports |
|---|---|---|
| `edge` | proxy | `${HTTP_PORT:-80}`, `${HTTPS_PORT:-443}` (tcp and udp) |
| `internal` | proxy, app, horizon, scheduler, reverb, postgres, valkey, rustfs, mail, mailpit | none in prod except `mail` port 25; dev publishes PostgreSQL on `127.0.0.1:${DEV_DB_PORT:-55439}` and Valkey on `127.0.0.1:${DEV_VALKEY_PORT:-56379}` |

Only `proxy` is ever published, plus port 25 of `mail` in production when the mail profile is on. The RustFS console and Mailpit are reached through the proxy (`monitor.…/storage`, `mail.…`), never through their own ports. The dev ports are unusual on purpose, so the stack does not collide with a PostgreSQL, Redis or Mailpit already running on the developer's machine. The proxy carries network aliases for every `*.${PLATFORM_DOMAIN}` host on `internal`, so containers reach the public hostnames without leaving Docker.

### PostgreSQL bootstrap

- The `postgres` superuser password comes from `secrets/db_superuser_password`. The data volume mounts at `/var/lib/postgresql`, which is the layout the PostgreSQL 18 image expects.
- `infra/postgres/entrypoint.sh` wraps the official entrypoint. It runs as root, reads the role passwords from the Compose secrets, and exports them for the init script. The host secret files can therefore stay mode 600.
- `infra/postgres/init/10-roles-and-databases.sh` runs once on an empty volume. It creates `helpdesk_owner`, `helpdesk_app` (`NOBYPASSRLS`) and `helpdesk_backup` (`BYPASSRLS`), and the `helpdesk` and `helpdesk_test` databases. Each database gets `pg_trgm`, `btree_gist` and `citext`, and default privileges so tables created by the owner are usable by the app role.
- A failed first init leaves a half-initialised volume. Remove the `pg-data` volume and start again.

### Services (`base.yaml`)

```yaml
x-backend: &backend
  image: ghcr.io/subham/smart-helpdesk-backend:${APP_VERSION:-latest}
  build: { context: ./backend, target: runtime }
  env_file: .env
  environment:
    PUID: ${PUID:-1000}
    PGID: ${PGID:-1000}
    LOG_CHANNEL: stderr
  networks: [internal]
  depends_on:
    postgres: { condition: service_healthy }
    valkey:   { condition: service_healthy }
  volumes:
    - app-storage:/var/www/html/storage/app/private   # temp files only; attachments live in S3
  logging: &logging
    driver: json-file
    options: { max-size: "10m", max-file: "3" }

services:
  proxy:
    image: ghcr.io/subham/smart-helpdesk-proxy:${APP_VERSION:-latest}
    build: { context: ., dockerfile: frontend/Dockerfile }
    env_file: .env
    environment:
      PLATFORM_DOMAIN: ${PLATFORM_DOMAIN}
      REALTIME_ENABLED: ${REALTIME_ENABLED:-false}
    ports: ["80:80", "443:443", "443:443/udp"]
    networks: [edge, internal]
    volumes:
      - caddy-data:/data
      - caddy-config:/config
    depends_on:
      app: { condition: service_healthy }
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://127.0.0.1:2019/metrics"]
      interval: 15s
      timeout: 5s
      retries: 5
    logging: *logging

  app:
    <<: *backend
    environment:
      HEALTHCHECK_PATH: /up
    # healthcheck inherited from the image: curls $HEALTHCHECK_PATH

  horizon:
    <<: *backend
    command: ["php", "artisan", "horizon"]
    stop_grace_period: 60s
    healthcheck:
      test: ["CMD", "php", "artisan", "horizon:status"]
      interval: 30s
      timeout: 10s
      retries: 3
    depends_on:
      app: { condition: service_healthy }

  scheduler:
    <<: *backend
    command: ["php", "artisan", "schedule:work"]
    deploy: { replicas: 1 }          # exactly one; tasks also use onOneServer()
    healthcheck:
      test: ["CMD", "php", "artisan", "schedule:heartbeat-check"]
      interval: 60s
      timeout: 10s
      retries: 3
    depends_on:
      app: { condition: service_healthy }

  reverb:
    <<: *backend
    profiles: [realtime]
    command: ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
    healthcheck:
      test: ["CMD-SHELL", "php -r 'exit(@fsockopen(\"127.0.0.1\",8080)?0:1);'"]
      interval: 15s
      timeout: 5s
      retries: 5

  postgres:
    image: postgres:18-trixie
    environment:
      POSTGRES_DB: ${DB_DATABASE}
      POSTGRES_USER: helpdesk_owner
      POSTGRES_PASSWORD_FILE: /run/secrets/db_owner_password
    secrets: [db_owner_password]
    volumes:
      - pg-data:/var/lib/postgresql/data
      - ./infra/postgres/init:/docker-entrypoint-initdb.d:ro   # creates helpdesk_app role, extensions
    networks: [internal]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U helpdesk_owner -d ${DB_DATABASE}"]
      interval: 5s
      timeout: 5s
      retries: 10
      start_period: 30s
    logging: *logging

  valkey:
    image: valkey/valkey:9-alpine
    command: ["valkey-server", "--save", "60", "1", "--maxmemory", "512mb", "--maxmemory-policy", "noeviction", "--requirepass", "${REDIS_PASSWORD}"]
    volumes: [valkey-data:/data]
    networks: [internal]
    healthcheck:
      test: ["CMD", "valkey-cli", "-a", "${REDIS_PASSWORD}", "ping"]
      interval: 5s
      retries: 10
    logging: *logging

  rustfs:
    image: rustfs/rustfs:1.0.0
    profiles: [storage]
    environment:
      RUSTFS_ACCESS_KEY: ${AWS_ACCESS_KEY_ID}
      RUSTFS_SECRET_KEY: ${AWS_SECRET_ACCESS_KEY}
      RUSTFS_VOLUMES: /data
    volumes: [object-data:/data]
    networks: [internal]
    healthcheck:
      test: ["CMD", "curl", "-f", "http://127.0.0.1:9000/health/live"]
      interval: 10s
      retries: 10
    logging: *logging

networks:
  edge: {}
  internal: { internal: true }

volumes:
  pg-data: {}
  valkey-data: {}
  object-data: {}
  app-storage: {}
  caddy-data: {}
  caddy-config: {}

secrets:
  db_owner_password: { file: ./secrets/db_owner_password }
  db_app_password:   { file: ./secrets/db_app_password }
```

`maxmemory-policy noeviction` is deliberate: Valkey holds queues, and evicting a job is data loss. Cache keys carry TTLs instead.

### Dev overlay (`dev.yaml`)

```yaml
services:
  app:
    profiles: [dev]
    build: { context: ./backend, target: runtime }
    environment:
      PHP_OPCACHE_VALIDATE_TIMESTAMPS: 1
      TELESCOPE_ENABLED: "true"
    volumes:
      - ./backend:/var/www/html            # Linux: bind mount is instant
    develop:
      watch:
        - action: rebuild
          path: ./backend/composer.lock
        - action: sync+restart
          path: ./backend/.env
          target: /var/www/html/.env
  proxy:
    profiles: [dev]
    environment: { TLS_MODE: internal }
  postgres:
    profiles: [dev]
    ports: ["127.0.0.1:5432:5432"]
  valkey:
    profiles: [dev]
    ports: ["127.0.0.1:6379:6379"]
  rustfs:
    profiles: [dev]
    ports: ["127.0.0.1:9000:9000", "127.0.0.1:9001:9001"]
  mailpit:
    image: axllent/mailpit:v1.31.1
    profiles: [dev]
    environment: { MP_MAX_MESSAGES: 500, MP_SMTP_AUTH_ACCEPT_ANY: 1, MP_SMTP_AUTH_ALLOW_INSECURE: 1 }
    ports: ["127.0.0.1:8025:8025"]
    networks: [internal]
    healthcheck: { test: ["CMD", "/mailpit", "readyz"], interval: 10s }
```

The Vite dev server runs on the host (`pnpm dev`) and proxies `/api` to `https://app.shp.localhost/acme`; `proxy` still serves the built SPA for E2E parity. Developers on macOS use `docker compose watch` with `sync` actions instead of the bind mount.

### Prod overlay (`prod.yaml`)

Design sketch below; the built file additionally sets `pull_policy: missing`, CPU limits, Valkey 640 MB and RustFS 384 MB limits, json-file rotation on every service, the Passport key and `certs/` mounts, `RUSTFS_CONSOLE_ENABLE=false`, and the `migrate` service. Verified with `docker compose -p shp-prodtest` on the workstation and on the Ansible rehearsal host ([production.md §As built](production.md#as-built-and-verified-m3-14-2026-09-21)).

```yaml
services:
  proxy:    { restart: unless-stopped, deploy: { resources: { limits: { memory: 256M } } } }
  app:      { restart: unless-stopped, deploy: { resources: { limits: { memory: 768M, cpus: "1.0" } } } }
  horizon:  { restart: unless-stopped, deploy: { resources: { limits: { memory: 768M } } } }
  scheduler:{ restart: unless-stopped, deploy: { resources: { limits: { memory: 256M } } } }
  reverb:   { restart: unless-stopped, deploy: { resources: { limits: { memory: 256M } } } }
  postgres: { restart: unless-stopped, shm_size: 256m, deploy: { resources: { limits: { memory: 1G } } } }
  valkey:   { restart: unless-stopped }
  rustfs:   { restart: unless-stopped }
```

### Demo overlay (`demo.yaml`)

```yaml
services:
  webhook-echo:
    image: ghcr.io/subham/smart-helpdesk-webhook-echo:${APP_VERSION:-latest}   # tools/webhook-echo, ~40 lines of Node
    profiles: [demo]
    environment: { WEBHOOK_SECRET: demo-secret }
    networks: [internal]
    ports: ["127.0.0.1:9100:9100"]
```

As built, webhook-echo is the `node:24-alpine` image running the mounted `tools/webhook-echo` (in `infra/compose/tools.yaml`, profiles `dev` and `demo`), and the planned `playwright` service was not added (M3-12): Playwright runs on the host or the CI runner.

## One-shot commands

Migrations and seeding are Compose `run` invocations, not init containers, so they are explicit and logged:

```sh
docker compose exec -e DB_CONNECTION=pgsql_owner app php artisan migrate --force   # just migrate
docker compose exec app php artisan storage:ensure-bucket                           # just bucket
docker compose exec app php artisan db:seed --class=DemoSeeder                      # just seed
```

The `app` service itself connects as `helpdesk_app` (no ownership, no `BYPASSRLS`). Migrations use the `pgsql_owner` connection, which reads `DB_OWNER_USERNAME` and `DB_OWNER_PASSWORD_FILE` ([03-architecture/tenancy.md](../03-architecture/tenancy.md)). `storage:ensure-bucket` creates the bucket and applies CORS for `CORS_ALLOWED_ORIGINS`; `--check` only reports.

## Environment and secrets

- `.env` (mode 0600) holds everything non-secret plus app-level secrets (`APP_KEY`, `REDIS_PASSWORD`, S3 keys, SMTP password). It is generated by Ansible in production ([ansible.md](ansible.md)) and copied from `.env.example` in dev.
- `backend/.env` holds the Laravel settings and is copied from `backend/.env.example` by `just setup`. The backend services load it through `BACKEND_ENV_FILE`.
- `secrets/db_superuser_password`, `db_owner_password`, `db_app_password` and `db_backup_password` are Compose file secrets generated by `just setup` with mode 600. PostgreSQL reads them through the entrypoint wrapper above. Laravel reads `DB_PASSWORD_FILE` and `DB_OWNER_PASSWORD_FILE` in `config/database.php`.
- In development the backend runs as the host user (`PUID`/`PGID`), so it can read the mode-600 files. On servers, Ansible sets the secret files' owner to the container user (see [ansible.md](ansible.md)).
- Presigned URLs are signed by the `s3-presign` disk against `AWS_PRESIGN_ENDPOINT` (`https://files.<domain>`), because the signature covers the Host header. The `s3` disk talks to the internal endpoint. Caddy forwards the Host header unchanged. Single-host mode strips `/files`, which breaks signatures, so single-host installs should give storage its own hostname (V1 backlog).
- Images never contain secrets; CI builds with no `.env`.

## Image tagging

CI tags `backend`, `proxy` and `webhook-echo` images with the git SHA and `main`; releases add a semver tag. `APP_VERSION` in `.env` selects what Compose pulls ([ci-cd.md](ci-cd.md)).

## Mail service (added by ADR-0018)

Profile `mail` adds `mail` (`stalwartlabs/stalwart:v0.16.22-alpine`, [versions.md](../01-research/versions.md)) with ports 25 (inbound SMTP, published by `prod.yaml` only), 587 (submission, internal), 143 (IMAP, internal: `mail:fetch-inbound` reads `inbound@`, M3-19) and 8080 (admin API and `/healthz/live`, internal; the proxy serves it at `mail.<domain>` and `monitor.<domain>/mail`), volume `mail-data` (`/var/lib/stalwart`: `config.json` and the RocksDB store in `data/`), healthcheck on 8080. `STALWART_RECOVERY_ADMIN=admin:${MAIL_ADMIN_PASSWORD}` is the management credential; it works in Stalwart's bootstrap mode and afterwards.

### As built (M3-18)

`infra/scripts/mail-init.sh` (`just mail-init`; Ansible runs it when `mail_profile` is true) configures the server and is idempotent:

1. starts `mail`; on an empty volume Stalwart is in bootstrap mode, and the script completes it through the JMAP management API (`x:Bootstrap/set`: hostname `mail.<domain>`, default domain, RocksDB path, logs to stdout, DKIM keys) and restarts it;
2. applies a declarative plan with Stalwart's CLI (`mail-cli` service, `stalwartlabs/cli:1.0.12`, profile `mail-tools`, run with `docker compose run --rm`): accounts `app@` (submission) and `inbound@` (the domain's catch-all, so `ticket+…@`, `support+…@` and `unsubscribe+…@` land there), sub-addressing on, listeners reconciled to exactly smtp 25 / submission 587 (no TLS: internal network only) / imap 143 / http 8080, IMAP login without TLS allowed (internal port only, for `mail:fetch-inbound`; MVP-SHORTCUT, V1-ML-06), SMTP AUTH on 587 only, `app@` may send as any address of the domain (other accounts must match their sender), and the remote route `relay` (when `MAIL_RELAY_HOST` is set) or `mx`;
3. keeps one RSA DKIM key (the Ed25519 key Stalwart also generates is deleted; DKIM management is set to manual, so the selector never rotates under published DNS);
4. restarts `mail` (listener changes need it) and prints the MX, SPF, DKIM and DMARC records plus the `MAIL_DKIM_SELECTOR`/`MAIL_DKIM_PUBLIC_KEY` lines for the backend `.env` (Settings → Email shows the same records).

| Variable (root `.env` or environment) | Meaning |
|---|---|
| `MAIL_ADMIN_PASSWORD`, `MAIL_APP_PASSWORD`, `MAIL_INBOUND_PASSWORD` | required; A–Z a–z 0–9 `. _ ~ @ + / = -`, at least 8 characters; Laravel's `MAIL_PASSWORD` equals `MAIL_APP_PASSWORD` |
| `MAIL_DOMAIN` (default `PLATFORM_DOMAIN`), `MAIL_HOSTNAME` (default `mail.<domain>`) | the mail domain and the server's name (MX target, EHLO) |
| `MAIL_RELAY_HOST`, `MAIL_RELAY_PORT` (587), `MAIL_RELAY_USERNAME`, `MAIL_RELAY_PASSWORD`, `MAIL_RELAY_IMPLICIT_TLS` (false; true for 465), `MAIL_RELAY_ALLOW_INVALID_CERTS` (false) | smart host for all remote mail; empty host = direct MX delivery |
| `MAIL_SPF_INCLUDE`, `MAIL_DMARC_POLICY` (`none`) | printed records; also read by Laravel for Settings → Email |

**Development: Mailpit and Stalwart side by side.** Laravel in dev always sends to Mailpit (`MAIL_HOST=mailpit` in `backend/.env`), so the stack works without the mail profile and every notification is visible at `https://mail.shp.localhost`. Stalwart is started only on demand (`just mail-init`); the dev `.env` sets `MAIL_RELAY_HOST=mailpit`, `MAIL_RELAY_PORT=1025`, so whatever Stalwart sends out also lands in Mailpit, DKIM-signed. `just mail-send-test you@example.com stalwart` submits a message through Stalwart (the recipe overrides `MAIL_HOST=mail` for one command) and `just mail-dkim-check` verifies the newest Mailpit message with dkimpy against the key in `backend/.env`, without public DNS. Port 25 is not published in dev. `docker compose --profile mail stop mail` stops it again; `docker compose down -v` removes `mail-data` with the other volumes.

**Production.** `prod.yaml` gives `mail` a restart policy, 512 MB / 0.5 CPU, log rotation and publishes `${MAIL_SMTP_PORT:-25}:25`. `COMPOSE_PROFILES` includes `mail`, and Laravel submits to `mail:587` as `app@<domain>` ([production.md §Mail](production.md#mail)). The `mail-data` volume holds the inbound mailbox and the DKIM key; the host backup timer does not copy it yet (the key can be regenerated with new DNS, and inbound mail is processed within minutes by M3-19).

docker-mailserver 14 remains the documented alternative image (ADR-0018); it is not wired.
