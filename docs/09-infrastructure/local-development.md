# Local development

Goal (NFR-OPS-01): a fresh clone becomes a running, seeded system in under ten minutes.

## Prerequisites

| Tool | Version | Installed by |
|---|---|---|
| Docker Engine + Compose plugin | 29.x / v5.x | distro packages or get.docker.com |
| mise | 2026.9+ | `curl https://mise.run \| sh` |
| PHP 8.5, Node 24, pnpm 12, just, OpenTofu 1.12, ansible-core 2.21 | pinned | `mise install` from `.mise.toml` |

```toml
# .mise.toml
[tools]
php = "8.5"
node = "24"
pnpm = "12.4.2"
just = "1.58.0"
opentofu = "1.12.6"
"pipx:ansible-core" = "2.21.4"
```

PHP and Node on the host are for editor tooling, Pint, Larastan and the Vite dev server; everything else runs in containers.

## First run

```sh
git clone git@github.com:subham/smart-helpdesk.git && cd smart-helpdesk
mise install
cp .env.example .env
just setup
```

`just setup` does, in order:

1. `mkdir -p secrets && openssl rand -hex 24 > secrets/db_owner_password` (and `db_app_password`) if missing.
2. `docker compose --profile dev --profile storage --profile demo up -d --wait --build`.
3. `docker compose run --rm app php artisan key:generate` (once) and `migrate --force` as the owner role.
4. `php artisan db:seed --class=DemoSeeder` (two tenants, agents, tickets, SLA breaches, duplicates).
5. `docker compose run --rm app php artisan scramble:export --path=openapi.json` then `pnpm -C frontend api:types`.
6. `pnpm -C frontend install`.
7. Prints the URLs below.

## Hostnames

Development mirrors the production host layout ([ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md)) under `shp.localhost`; tenants are workspaces in the SPA path, not hosts. Chrome, Edge and Firefox resolve any `*.localhost` name to loopback without configuration (RFC 6761); `curl` and Node also resolve it on systemd-resolved hosts. If a tool does not, add to `/etc/hosts`:

```text
127.0.0.1 shp.localhost app.shp.localhost api.shp.localhost admin.shp.localhost monitor.shp.localhost docs.shp.localhost files.shp.localhost mail.shp.localhost
```

Caddy issues certificates from its internal CA (`tls internal`); trust it once with `docker compose exec proxy caddy trust` or accept the browser warning. Playwright runs with `ignoreHTTPSErrors: true` locally.

| URL | What |
|---|---|
| https://shp.localhost | landing page |
| https://app.shp.localhost/acme | Acme workspace (SPA) |
| https://app.shp.localhost/globex | Globex workspace |
| https://api.shp.localhost/v1 | REST API (`/v1/ping` for a quick check) |
| https://admin.shp.localhost | Platform super admin |
| https://monitor.shp.localhost/horizon | Horizon (queues) |
| https://monitor.shp.localhost/telescope | Telescope (dev only) |
| https://monitor.shp.localhost/health | Health dashboard / JSON |
| https://monitor.shp.localhost/storage | RustFS console (bucket `helpdesk`) |
| https://monitor.shp.localhost/mail | Stalwart admin (profile `mail`) |
| https://docs.shp.localhost | OpenAPI UI |
| https://files.shp.localhost | S3 endpoint used by presigned URLs |
| https://mail.shp.localhost | Mailpit (all outgoing mail in dev) |
| http://localhost:9100 | webhook-echo (demo profile): prints and verifies signed deliveries |

Seeded logins are printed by the seeder (`owner@acme.test`, `manager@acme.test`, `agent1@acme.test` … password `password`, plus `admin@platform.test`).

## justfile

```make
default:                 @just --list
setup:                   ./infra/scripts/setup.sh
up:                      docker compose --profile dev --profile storage up -d --wait
down:                    docker compose down
fresh:                   docker compose down -v && just up && just migrate && just seed
logs service="app":      docker compose logs -f {{service}}
shell:                   docker compose exec app bash
tinker:                  docker compose exec app php artisan tinker
migrate:                 docker compose run --rm -e DB_USERNAME=helpdesk_owner -e DB_PASSWORD_FILE=/run/secrets/db_owner_password app php artisan migrate --force
seed:                    docker compose exec app php artisan db:seed --class=DemoSeeder
demo-reset:              docker compose exec app php artisan demo:reset          # truncates tenant data, reseeds, resets clock offset
demo-tick minutes="30":  docker compose exec app php artisan demo:tick {{minutes}} # advances the demo clock; SLA sweep runs immediately
test:                    just test-backend && just test-frontend
test-backend *args:      docker compose exec app php artisan test --parallel {{args}}
test-frontend:           pnpm -C frontend test && pnpm -C frontend test:browser
e2e:                     docker compose --profile dev --profile storage --profile demo --profile e2e up -d --wait && docker compose run --rm playwright
lint:                    docker compose exec app vendor/bin/pint --test && docker compose exec app vendor/bin/phpstan analyse && pnpm -C frontend lint && pnpm -C frontend typecheck
fmt:                     docker compose exec app vendor/bin/pint && pnpm -C frontend format
types:                   docker compose exec app php artisan scramble:export --path=openapi.json && pnpm -C frontend api:types
reproduce seed="42":     docker compose exec app php artisan experiment:run --all --seed={{seed}} && python3 experiments/plots.py
deploy env:              cd infra/tofu/envs/{{env}} && tofu apply && cd ../../../ansible && ansible-playbook -i inventory/{{env}}.ini site.yml
```

`demo:tick` exists only when `APP_ENV != production`; it shifts the injectable `Clock` by an offset stored in cache so the SLA demo does not wait real minutes ([12-academic/demo-plan.md](../12-academic/demo-plan.md)).

## Frontend workflows

| Mode | Command | When |
|---|---|---|
| Vite dev server | `pnpm -C frontend dev` → http://localhost:5173 served as `https://app.shp.localhost` through Caddy (`handle` → `host.docker.internal:5173` when `VITE_DEV=1`), calling `https://api.shp.localhost` directly with credentials; `SESSION_DOMAIN=.shp.localhost` and `SANCTUM_STATEFUL_DOMAINS=app.shp.localhost` | day-to-day UI work, HMR |
| Served by Caddy | `pnpm -C frontend build && docker compose up -d --build proxy` | E2E, checking the runtime `config.json`, before a PR |

`pnpm -C frontend api:types` must be re-run after backend resource/request changes; CI fails on drift ([ci-cd.md](ci-cd.md)).

## Backend workflows

- Code is bind-mounted; opcache timestamps are validated in dev so edits are live. `config:cache`/`route:cache` are never run in dev.
- Queue jobs: Horizon runs in its own container; `just logs horizon`. Restart it after changing job code (`docker compose restart horizon`) because workers hold code in memory.
- Scheduler: `just logs scheduler`; run a task manually with `docker compose exec app php artisan sla:evaluate`.
- Telescope at the admin host records requests, queries, jobs and mail; `telescope:prune` runs nightly in dev.
- Tests use the `helpdesk_test` database created by `infra/postgres/init/02-test-db.sql` and run against PostgreSQL, never SQLite.

## Coding agents

`laravel/boost` is installed as a dev dependency (`php artisan boost:install`): it exposes version-pinned Laravel docs, database and log inspection to Claude Code / Codex over MCP. Project guidelines live in `backend/.ai/guidelines/` and the root `CLAUDE.md`, which point agents at [docs/03-architecture/backend.md](../03-architecture/backend.md) conventions and the current roadmap task.

## Common problems

| Symptom | Cause | Fix |
|---|---|---|
| `permission denied` writing `storage/` | container UID ≠ host UID | set `PUID`/`PGID` in `.env` to `id -u`/`id -g`, `docker compose up -d --force-recreate app` |
| `NET::ERR_CERT_AUTHORITY_INVALID` | Caddy internal CA not trusted | `docker compose exec proxy caddy trust`, or accept once per host |
| Login returns 419 | CSRF cookie not shared between `app` and `api` | open `app.shp.localhost`, not `localhost:5173`; check `SESSION_DOMAIN=.shp.localhost` and `SANCTUM_STATEFUL_DOMAINS=app.shp.localhost` |
| Workspace page shows "workspace not found" | slug not seeded or reserved | `php artisan tenants:list`; use a seeded slug |
| Uploads fail with CORS error | bucket CORS missing after `down -v` | `php artisan storage:ensure-bucket` (runs in `just setup`) |
| Jobs never run | Horizon paused or crashed | `just logs horizon`; `php artisan horizon:status`; restart |
| `SQLSTATE permission denied for table` | migration ran as `helpdesk_app` | use `just migrate` (owner role) |
| Old code in queue workers | Horizon caches code | `docker compose restart horizon` |
| Port 5432 already in use | local PostgreSQL | change the dev published port in `dev.yaml`; containers are unaffected |
