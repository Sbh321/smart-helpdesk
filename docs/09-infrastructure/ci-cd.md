# CI/CD

GitHub Actions. Three path-filtered workflows plus image build and a manual deploy. Goal: every push runs lint, static analysis, tests and the contract check in under 10 minutes; `main` publishes images; deployment is a human-triggered Ansible run.

## As built (M1-05)

`.github/workflows/` currently holds `backend.yml`, `frontend.yml`, `build.yml` and `security.yml`.
`e2e.yml`, `infra.yml` and `deploy.yml` arrive with the tasks that need them (M2-E2E, M3-14).
The YAML below is the design; the differences that are already real:

| Difference | Reason |
|---|---|
| The PostgreSQL service runs as the `postgres` superuser, and a step runs `infra/postgres/init/10-roles-and-databases.sh` | one definition of roles, databases, extensions and grants for CI and development; the script is idempotent |
| Tests run `vendor/bin/pest`, not `php artisan test --parallel` | parallel testing needs one database per worker, and the owner role may not create databases |
| Coverage is uploaded but not gated | the ≥ 70 % gate is switched on in M3-11, when the feature tests exist |
| `pnpm lint`, `pnpm typecheck`, `pnpm tokens:check`, `pnpm test`, `pnpm test:browser`, `pnpm build` | the scripts the repository actually defines; `tokens:check` is the colour-contrast check from M1-11 |
| `build.yml` builds `backend` (target `production`) and `proxy` (target `runtime`) | `webhook-echo` does not exist yet (M2) |
| `security.yml` runs gitleaks on every push and the dependency review on pull requests | dependency review only works on pull requests |
| Badges in the README use the slug `subham/smart-helpdesk` | the repository has no remote yet |

The workflows have not run on GitHub yet, because no remote is configured. Each step was checked
locally: the database script is idempotent against the running container, every frontend script is
green, and the backend commands are the ones used in development.

## Workflows

```text
.github/workflows/
├── backend.yml      # paths: backend/**, .github/workflows/backend.yml
├── frontend.yml     # paths: frontend/**, backend/openapi.json (drift check), .github/workflows/frontend.yml
├── e2e.yml          # every push to main, PRs to main, nightly (Playwright + axe against Compose)
├── build.yml        # on push to main and tags: build + push images to GHCR
├── infra.yml        # paths: infra/**: tofu fmt/validate, ansible-lint, syntax-check
└── deploy.yml       # workflow_dispatch: environment + app_version → ansible deploy.yml
```

### backend.yml

As built (2026-09-26), the workflow has two jobs that run side by side. `checks` runs composer validate and
audit, Pint, PHPStan, the migration round trip and the OpenAPI drift check. `test` is a matrix of four jobs.
Each runs `vendor/bin/pest --shard=N/4` with pcov against its own Postgres and Valkey services and uploads
`backend-coverage-N`. Pest's built-in sharding needs no new dependency (paratest would). Before the split, one
job ran all ~2 000 tests in about 11 minutes, and a push took 13–19 minutes. The sketch below is the original
single-job design.

```yaml
name: backend
on:
  push: { branches: [main], paths: ['backend/**', '.github/workflows/backend.yml'] }
  pull_request: { paths: ['backend/**', '.github/workflows/backend.yml'] }
defaults: { run: { working-directory: backend } }
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:18-trixie
        env: { POSTGRES_USER: helpdesk_owner, POSTGRES_PASSWORD: secret, POSTGRES_DB: helpdesk_test }
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U helpdesk_owner" --health-interval 5s --health-timeout 5s --health-retries 10
      valkey:
        image: valkey/valkey:9-alpine
        ports: ['6379:6379']
        options: >-
          --health-cmd "valkey-cli ping" --health-interval 5s --health-timeout 5s --health-retries 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', extensions: 'pdo_pgsql, redis, intl, gd, bcmath, zip, pcntl, sockets', coverage: pcov, tools: 'composer:v2' }
      - id: cc
        run: echo "dir=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"
      - uses: actions/cache@v4
        with: { path: "${{ steps.cc.outputs.dir }}", key: "composer-${{ runner.os }}-${{ hashFiles('backend/composer.lock') }}", restore-keys: "composer-${{ runner.os }}-" }
      - run: composer install --prefer-dist --no-progress --no-interaction
      - run: composer validate --strict
      - run: composer audit
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --memory-limit=1G
      - name: Prepare database roles and extensions
        run: psql postgresql://helpdesk_owner:secret@localhost:5432/helpdesk_test -f ../infra/postgres/init/01-roles.sql
      - name: Migration round-trip
        env: { DB_USERNAME: helpdesk_owner, DB_PASSWORD: secret }
        run: |
          php artisan migrate:fresh --force
          php artisan migrate:rollback --force --step=1000
          php artisan migrate --force
          php artisan migrate:status | grep -q Pending && exit 1 || true
      - name: Tests (runtime role, RLS active)
        env: { DB_USERNAME: helpdesk_app, DB_PASSWORD: secret, REDIS_HOST: 127.0.0.1 }
        run: php artisan test --parallel --coverage --min=70 --coverage-clover=coverage.xml
      - name: Export OpenAPI
        env: { DB_USERNAME: helpdesk_owner, DB_PASSWORD: secret }
        run: php artisan scramble:export --path=openapi.json && git diff --exit-code openapi.json
      - uses: actions/upload-artifact@v4
        with: { name: openapi, path: backend/openapi.json }
      - uses: actions/upload-artifact@v4
        if: always()
        with: { name: backend-coverage, path: backend/coverage.xml }
```

`git diff --exit-code openapi.json` enforces that the committed OpenAPI document matches the code; developers run `just types` before pushing. Scramble needs a migrated database, hence the order.

### frontend.yml

```yaml
name: frontend
on:
  push: { branches: [main], paths: ['frontend/**', 'backend/openapi.json', '.github/workflows/frontend.yml'] }
  pull_request: { paths: ['frontend/**', 'backend/openapi.json', '.github/workflows/frontend.yml'] }
defaults: { run: { working-directory: frontend } }
jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4                       # reads packageManager
      - uses: actions/setup-node@v4
        with: { node-version: 24, cache: pnpm, cache-dependency-path: frontend/pnpm-lock.yaml }
      - run: pnpm install --frozen-lockfile
      - run: pnpm audit --audit-level=high
      - run: pnpm biome ci .
      - run: pnpm typecheck
      - name: API types drift
        run: pnpm api:types && git diff --exit-code src/lib/api/schema.d.ts
      - run: pnpm test -- --coverage
      - run: pnpm exec playwright install --with-deps chromium
      - run: pnpm test:browser
      - run: pnpm build
      - uses: actions/upload-artifact@v4
        with: { name: spa-dist, path: frontend/dist }
```

### e2e.yml

As built (M3-12): [`.github/workflows/e2e.yml`](../../.github/workflows/e2e.yml), on pull requests to `main`, pushes to `main`, nightly at 20:30 UTC and by hand. It runs the stack exactly as on a workstation (the development override: backend `development` target with the checkout bind-mounted, Caddy `tls internal`, Mailpit, webhook-echo), because the demo reset refuses in production and the production image has no dev dependencies.

| Step | What |
|---|---|
| settings | `.env` and `backend/.env` from the examples; `PUID`/`PGID` set to the runner user (the bind mount belongs to it); random database secrets |
| hosts | `*.shp.localhost` added to `/etc/hosts` for Node (Playwright's request contexts); Chromium resolves `*.localhost` to loopback itself |
| images | `docker compose build app proxy` (the proxy image carries the built SPA) |
| backend | `composer install` in a one-off `app` container (vendor cached on `composer.lock`), `key:generate`, `passport:keys` |
| stack | `up -d --wait` with the `dev`, `storage` and `demo` profiles, `migrate` as the owner role, `storage:ensure-bucket` |
| suite | `pnpm install`, `playwright install --with-deps chromium`, `node --test` in `tools/webhook-echo`, a curl smoke check, `pnpm e2e` (the global setup runs `demo:reset`) |
| on failure | the HTML report, traces and screenshots (`frontend/playwright-report`, `frontend/test-results`) and `docker compose logs` as the `e2e-report` artefact; the JUnit file always |

Playwright in CI: `workers: 2`, `retries: 1`, `trace: 'retain-on-failure'`, reporters `list` + `junit` + `html`; `ignoreHTTPSErrors` against Caddy's internal CA. The axe scans are part of the suite ([testing.md](../10-quality/testing.md) §End-to-end). Budget: about 5 min to build and start, 1 min for the suite; the job times out at 30 min.

### build.yml

```yaml
name: build
on:
  push: { branches: [main], tags: ['v*'] }
permissions: { contents: read, packages: write }
jobs:
  images:
    runs-on: ubuntu-latest
    strategy: { matrix: { image: [backend, proxy, webhook-echo] } }
    steps:
      - uses: actions/checkout@v4
      - uses: docker/setup-buildx-action@v3
      - uses: docker/login-action@v3
        with: { registry: ghcr.io, username: "${{ github.actor }}", password: "${{ secrets.GITHUB_TOKEN }}" }
      - uses: docker/metadata-action@v5
        id: meta
        with: { images: "ghcr.io/${{ github.repository_owner }}/smart-helpdesk-${{ matrix.image }}", tags: "type=sha,format=long\ntype=ref,event=branch\ntype=semver,pattern={{version}}" }
      - uses: docker/build-push-action@v6
        with:
          context: ${{ matrix.image == 'backend' && './backend' || matrix.image == 'proxy' && '.' || './tools/webhook-echo' }}
          file: ${{ matrix.image == 'proxy' && 'frontend/Dockerfile' || '' }}
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          cache-from: type=gha
          cache-to: type=gha,mode=max
```

### deploy.yml

`workflow_dispatch` with inputs `environment` (`reference`) and `app_version`; job uses the `reference` GitHub environment (required reviewer = the developer), installs ansible-core via mise, writes the SSH key and the host secrets file from environment secrets, runs `ansible-playbook -i inventory/reference.ini deploy.yml -e app_version=…`. On-prem customers deploy from their own machine; CI never has their credentials.

## The required-status-check trap

Path-filtered workflows do not run for PRs that do not touch their paths, so a branch-protection rule that *requires* `backend/test` would block a docs-only PR forever. Fix used here: **do not mark path-filtered jobs as required**; instead `e2e.yml` (which runs on every `main` push) is the merge gate, and a tiny always-run `ci-ok.yml` job aggregates results with `dorny/paths-filter` if stricter protection is wanted later. For a solo developer the first option is sufficient.

## Caching rules

- Composer: cache `composer config cache-files-dir`, never `vendor/`.
- pnpm: `actions/setup-node` with `cache: pnpm` after `pnpm/action-setup`.
- Playwright browsers: `~/.cache/ms-playwright` keyed by the Playwright version.
- Docker: `type=gha` layer cache in `build.yml`; the E2E job builds locally (no cache) to keep it simple.

## Secrets

Repository secrets: none for tests. Environment `reference`: `DO_SSH_PRIVATE_KEY`, `REFERENCE_HOST_SECRETS` (the `host_vars` YAML), `GHCR_READ_TOKEN` if images go private. `GITHUB_TOKEN` pushes images. Dependabot updates Composer, pnpm and Actions weekly.

## Time budget

Backend and frontend workflows: milestone 1 (day 5, ≈ 0.5 day). E2E and build: milestone 2 day 10 (≈ 0.5 day). Infra lint and deploy dispatch: milestone 3 (≈ 0.25 day). Target durations: backend 6 min, frontend 5 min, e2e 12 min, build 6 min.
