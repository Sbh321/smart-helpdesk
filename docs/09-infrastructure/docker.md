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
envsubst '$PLATFORM_DOMAIN $REALTIME_ENABLED $REALTIME_HOST $STORAGE_PUBLIC_ENDPOINT' \
  < /srv/config.template.json > /srv/config.json
exec "$@"
```

`/srv/config.json` is what the SPA fetches at boot ([03-architecture/frontend.md](../03-architecture/frontend.md) §Runtime configuration). Nothing environment-specific is baked at build time.

### Caddyfile (proxy)

Host layout per [ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md). `{$PLATFORM_DOMAIN}` is `shp.subhambhandari.com.np` in production and `shp.localhost` in development; `{$TLS}` is empty in production (automatic ACME per host), `internal` in development, or `/certs/fullchain.pem /certs/privkey.pem` for a customer certificate.

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
	@realtime path /app/* /apps/*
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
	handle /storage/* { uri strip_prefix /storage
		reverse_proxy rustfs:9001 }
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

```text
compose.yaml                 # root: include + project name
infra/compose/base.yaml      # every service, no ports, no bind mounts
infra/compose/dev.yaml       # profiles dev: bind mounts, published ports, mailpit, telescope
infra/compose/prod.yaml      # restart policies, resource limits, logging, secrets
infra/compose/demo.yaml      # profile demo: webhook-echo, seeded fixtures
```

```yaml
# compose.yaml
name: smart-helpdesk
include:
  - path: infra/compose/base.yaml
    env_file: .env
  - path: infra/compose/dev.yaml     # harmless in prod: all its services carry `profiles: [dev]`
  - path: infra/compose/prod.yaml
  - path: infra/compose/demo.yaml
```

Profiles: `dev` (mailpit, telescope, published DB/Valkey ports, bind mounts), `storage` (rustfs), `realtime` (reverb), `demo` (webhook-echo), `e2e` (playwright runner). Production on-prem typically runs `COMPOSE_PROFILES=storage`, cloud runs no profile (managed storage), and both add `realtime` when enabled.

### Networks and port rules

| Network | Members | Published ports |
|---|---|---|
| `edge` | proxy | `80`, `443` (and `443/udp` for HTTP/3) |
| `internal` | proxy, app, horizon, scheduler, reverb, postgres, valkey, rustfs, mailpit | none in prod; `dev` profile publishes 5432, 6379, 9000/9001, 8025 on `127.0.0.1` only |

Only `proxy` is ever published in production. The RustFS console and Mailpit are dev conveniences.

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
  playwright:
    image: mcr.microsoft.com/playwright:v1.63.0-noble
    profiles: [e2e]
    working_dir: /work/frontend
    volumes: [./:/work]
    environment: { BASE_URL: https://app.shp.localhost/acme, NODE_TLS_REJECT_UNAUTHORIZED: "0" }
    networks: [internal]
    entrypoint: ["pnpm", "exec", "playwright", "test"]
```

## One-shot commands

Migrations and seeding are Compose `run` invocations, not init containers, so they are explicit and logged:

```sh
docker compose run --rm app php artisan migrate --force        # DB_USERNAME=helpdesk_owner via env override
docker compose run --rm app php artisan db:seed --class=DemoSeeder
```

The `app` service itself connects as `helpdesk_app` (no ownership, no `BYPASSRLS`); `migrate` runs with `DB_USERNAME`/`DB_PASSWORD` overridden to the owner role ([03-architecture/tenancy.md](../03-architecture/tenancy.md)).

## Environment and secrets

- `.env` (mode 0600) holds everything non-secret plus app-level secrets (`APP_KEY`, `REDIS_PASSWORD`, S3 keys, SMTP password). It is generated by Ansible in production ([ansible.md](ansible.md)) and copied from `.env.example` in dev.
- `secrets/db_owner_password` and `secrets/db_app_password` are Compose file secrets; PostgreSQL reads `POSTGRES_PASSWORD_FILE`; the backend entrypoint exports `DB_PASSWORD` from `/run/secrets/db_app_password` when present.
- Images never contain secrets; CI builds with no `.env`.

## Image tagging

CI tags `backend`, `proxy` and `webhook-echo` images with the git SHA and `main`; releases add a semver tag. `APP_VERSION` in `.env` selects what Compose pulls ([ci-cd.md](ci-cd.md)).

## Mail service (added by ADR-0018)

Profile `mail` adds `mail` (`stalwartlabs/stalwart`, pinned tag) with ports 25 (inbound SMTP, published), 587 (submission, internal), 143/993 (IMAP, internal), 8080 (admin, proxied at `/mail-admin` for platform admins only), volume `mail-data`, healthcheck on the admin HTTP port. First boot runs `infra/scripts/mail-init.sh`: creates the `app` submission account, the `inbound` mailbox with catch-all for `ticket+*@` and `support+*@`, generates DKIM keys for `PLATFORM_DOMAIN` and prints the DNS records. `MAIL_RELAY_HOST/PORT/USERNAME/PASSWORD` configure the smart host when port 25 is blocked. Dev keeps Mailpit for viewing outbound mail; `just mail-send-test` and `just mail-inject "<eml file>"` exercise the inbound path. docker-mailserver 14 is the documented alternative image with the same ports and volume names.
