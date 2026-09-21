# Local development

Goal (NFR-OPS-01): a fresh clone becomes a running, seeded system in under ten minutes.

## Prerequisites

| Tool | Version | Installed by |
|---|---|---|
| Docker Engine + Compose plugin | 29.x / v5.x | distro packages or get.docker.com |
| mise | 2026.9+ | `curl https://mise.run \| sh` |
| Node 24, pnpm 12, just, lefthook, OpenTofu 1.12, ansible-core 2.21 | pinned | `mise install` from `.mise.toml` (PHP 8.5 runs in the `app` container; any host PHP ≥ 8.4 is enough for editor tooling) |

```toml
# .mise.toml
[tools]
# php runs in the app container (see .mise.toml note)
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
just setup
```

`just setup` (`infra/scripts/setup.sh`) does, in order:

1. Copies `.env.example` to `.env` if missing.
2. Generates `secrets/db_superuser_password`, `db_owner_password`, `db_app_password` and `db_backup_password` (mode 600) if missing.
3. Copies `backend/.env.example` to `backend/.env` if missing.
4. `docker compose --profile dev --profile storage up -d --wait --build`.
5. `key:generate` only when `APP_KEY` is empty, then `migrate --force` on the owner connection.
6. `storage:ensure-bucket` (bucket `helpdesk` plus CORS for the app origin).
7. `db:seed --class=DemoSeeder` once the seeder exists (roadmap M2).
8. `scramble:export`, `pnpm -C frontend install` and `pnpm -C frontend api:types`.
9. Runs `infra/scripts/smoke.sh` (also `just smoke`) and prints the URLs below.

### Ports on the host

| Variable (root `.env`) | Default | Purpose |
|---|---|---|
| `HTTPS_PORT` | 443 | every `*.shp.localhost` host |
| `HTTP_PORT` | 8081 in `.env.example` | HTTP redirect only; 80 is often taken by a local web server |
| `DEV_DB_PORT` | 55439 | PostgreSQL on `127.0.0.1` for database tools (`postgres` superuser, or the helpdesk roles) |
| `DEV_VALKEY_PORT` | 56379 | Valkey on `127.0.0.1` |

Mailpit and the RustFS console have no host ports; use `mail.shp.localhost` and `monitor.shp.localhost/storage`. HTTPS must stay on 443, because the SPA, the API and the presigned URLs use port-less origins.

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
| https://monitor.shp.localhost/storage | RustFS console (bucket `helpdesk`; redirects to `/rustfs/console/`; login is `STORAGE_ACCESS_KEY` / `STORAGE_SECRET_KEY`) |
| https://monitor.shp.localhost/mail | Stalwart admin (profile `mail`) |
| https://docs.shp.localhost | OpenAPI UI |
| https://files.shp.localhost | S3 endpoint used by presigned URLs |
| https://mail.shp.localhost | Mailpit (all outgoing mail in dev) |
| http://localhost:9100 | webhook-echo (demo profile): prints and verifies signed deliveries |

Seeded logins are printed by the seeder (`owner@acme.test`, `manager@acme.test`, `agent1@acme.test` … password `password`, plus `admin@platform.test`).

Until `DemoSeeder` exists (M2), create a workspace and an administrator by hand:

```sh
docker compose exec app php artisan db:seed --force                 # permission catalogue and default roles
docker compose exec app php artisan platform:create-admin           # platform super admin for admin.shp.localhost
docker compose exec app php artisan platform:create-tenant acme "Acme Corp" --owner=priya@acme.test
```

The last command prints an invitation link; open it in the SPA to set the owner's password, or find
the invitation mail in Mailpit. `identity:sync-permissions` re-syncs the catalogue at any time.

## justfile

```make
default:                 @just --list
setup:                   ./infra/scripts/setup.sh
up:                      docker compose --profile dev --profile storage up -d --wait
down:                    docker compose down
fresh:                   docker compose down -v && just up && just migrate && just bucket && just seed
logs service="app":      docker compose logs -f {{service}}
shell:                   docker compose exec app bash
tinker:                  docker compose exec app php artisan tinker
migrate:                 docker compose exec -e DB_CONNECTION=pgsql_owner app php artisan migrate --force
bucket:                  docker compose exec app php artisan storage:ensure-bucket
smoke:                   ./infra/scripts/smoke.sh                                 # curl checks: hosts, CORS, consoles
seed:                    docker compose exec app php artisan db:seed --class=DemoSeeder
demo-reset:              docker compose exec app php artisan demo:reset          # truncates tenant data, reseeds, resets clock offset
demo-tick minutes="30":  docker compose exec app php artisan demo:tick {{minutes}} # advances the demo clock; SLA sweep runs immediately
test:                    just test-backend && just test-frontend
test-backend *args:      docker compose exec -T app vendor/bin/pest {{args}}         # helpdesk_test only; migrations run as the owner
coverage *args:          unit and contract tests with pcov --coverage              # pcov ships in the development image, off by default
test-frontend:           pnpm -C frontend test && pnpm -C frontend test:browser
e2e:                     docker compose --profile dev --profile storage --profile demo --profile e2e up -d --wait && docker compose run --rm playwright
lint:                    docker compose exec app vendor/bin/pint --test && docker compose exec app vendor/bin/phpstan analyse && pnpm -C frontend lint && pnpm -C frontend typecheck
fmt:                     docker compose exec app vendor/bin/pint && pnpm -C frontend format
types:                   docker compose exec app php artisan scramble:export --path=openapi.json && pnpm -C frontend api:types
reproduce seed db:       docker compose run --rm --no-deps -v ./experiments:/var/www/experiments app php artisan experiment:run all --seed={{seed}} && uv run --with-requirements experiments/requirements.txt python experiments/plots.py  (E6 migrates the *_test database {{db}}; experiments/README.md)
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
- Tests use the `helpdesk_test` database created by `infra/postgres/init/10-roles-and-databases.sh` and run against PostgreSQL, never SQLite. Run them inside the `app` container (`just test-backend`).
- `phpunit.xml` sets its values as `<server … force="true">`. Compose injects `backend/.env` into the container, and Laravel reads `$_SERVER` first, so plain `<env>` entries would leave the tests on the dev database.
- `Tests\TestCase` uses `RefreshDatabase` and migrates through `pgsql_owner`, while the tests query as `helpdesk_app`. It refuses to refresh any database whose name does not end in `_test`.
- Telescope is at `monitor.shp.localhost/telescope` in local only.

## Coding agents

`laravel/boost` is optional and not installed yet (its installer is interactive; see M1-02). When added with `composer require --dev laravel/boost` and `php artisan boost:install`, it exposes version-pinned Laravel docs, database and log inspection to Claude Code / Codex over MCP. Project guidelines live in `backend/.ai/guidelines/` and the root `CLAUDE.md`, which point agents at [docs/03-architecture/backend.md](../03-architecture/backend.md) conventions and the current roadmap task.

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
| `port is already allocated` on `up` | another project or a local service uses the port | change `DEV_DB_PORT`, `DEV_VALKEY_PORT` or `HTTP_PORT` in the root `.env`; containers are unaffected |
| `postgres` exits with `Permission denied` on `/run/secrets/...` | the init script read secrets as the postgres user | the entrypoint wrapper in `infra/postgres/` must be mounted; then remove the half-initialised volume: `docker compose down && docker volume rm smart-helpdesk_pg-data` |
| `horizon` exits with `RedisException` | `backend/.env` is a Laravel skeleton file (`REDIS_HOST=127.0.0.1`) | recreate it from `backend/.env.example`, keep `APP_KEY`, then `docker compose up -d --force-recreate app horizon scheduler` |
| `toomanyrequests` pulling images | Docker Hub anonymous pull limit | log in, or set the mirror image variables listed in the root `.env.example` |
| Presigned upload returns `SignatureDoesNotMatch` | URL signed for the internal endpoint | sign with the `s3-presign` disk (`AWS_PRESIGN_ENDPOINT=https://files.shp.localhost`) |
