# Smart Helpdesk task runner. `just` lists the recipes.
# Recipes that need services added by later roadmap tasks fail with a clear message until then.

set dotenv-load := false

default:
    @just --list

# first-time setup: secrets, containers, database, demo data, API types
setup:
    ./infra/scripts/setup.sh

# start the development stack
up:
    docker compose --profile dev --profile storage up -d --wait

# stop the stack
down:
    docker compose down

# recreate everything from scratch
fresh:
    docker compose down -v
    just up
    just migrate
    just bucket
    just seed

# curl checks against the running stack (hosts, CORS, consoles)
smoke:
    ./infra/scripts/smoke.sh

# create the object-storage bucket and apply CORS (idempotent)
bucket:
    docker compose exec app php artisan storage:ensure-bucket

logs service="app":
    docker compose logs -f {{service}}

shell:
    docker compose exec app bash

tinker:
    docker compose exec app php artisan tinker

migrate:
    docker compose exec -e DB_CONNECTION=pgsql_owner app php artisan migrate --force

seed:
    docker compose exec app php artisan db:seed --class=DemoSeeder

demo-reset:
    docker compose exec app php artisan demo:reset

demo-tick minutes="30":
    docker compose exec app php artisan demo:tick {{minutes}}

test: test-backend test-frontend

# no --parallel: the owner role cannot create per-worker databases, and the suite is fast
test-backend *args:
    docker compose exec -T app vendor/bin/pest {{args}}

# unit and contract coverage with pcov (the algorithm namespaces must stay at 100 %, definition-of-done.md)
coverage *args:
    docker compose exec -T app php -d pcov.enabled=1 vendor/bin/pest tests/Unit tests/Contracts --coverage {{args}}

test-frontend:
    pnpm -C frontend test

# Playwright suite + axe scans on the host against the stack; global setup runs demo:reset (E2E_RESET=0 skips it)
e2e *args:
    docker compose --profile dev --profile storage --profile demo up -d --wait
    pnpm -C frontend e2e {{args}}

# build the SPA and copy it into the running proxy (E2E against the current source without rebuilding the image; lost when the container is recreated)
spa-refresh:
    pnpm -C frontend build
    docker compose cp frontend/dist/. proxy:/srv/app/

lint:
    docker compose exec app vendor/bin/pint --test
    docker compose exec app vendor/bin/phpstan analyse --no-progress
    pnpm -C frontend lint
    pnpm -C frontend typecheck

fmt:
    docker compose exec app vendor/bin/pint
    pnpm -C frontend format

# regenerate backend/openapi.json and frontend/src/lib/api/schema.d.ts (docs/07-api/documentation.md)
api-docs:
    docker compose exec -T app php artisan scramble:export --path=openapi.json
    pnpm -C frontend api:types

# alias of api-docs, kept because the command list names it
types: api-docs

# fail when the committed OpenAPI document or the generated types are stale (CI runs this too)
api-drift:
    ./infra/scripts/api-drift.sh

# print the dated plan and the tasks that can start now
plan:
    python3 roadmap/tools/schedule.py --ready

# regenerate every experiment table (E1-E4, E6) and plots 1-10 (experiments/README.md); E6 wipes the *_test database named by db
reproduce seed="42" db="helpdesk_test":
    docker compose run --rm --no-deps -T -e EXPERIMENTS_DATABASE={{db}} -v "{{justfile_directory()}}/experiments:/var/www/experiments" app php -d memory_limit=2G artisan experiment:run all --seed={{seed}} --commit="$(git describe --always --dirty)"
    uv run --with-requirements experiments/requirements.txt python experiments/plots.py

report:
    cd report && ./build.sh

# provision with OpenTofu (envs/reference or envs/existing) and configure the host (terraform.md, ansible.md)
deploy env:
    cd infra/tofu/envs/{{env}} && tofu apply
    cd infra/ansible && ansible-playbook -i inventory/{{env}}.ini site.yml

# static checks of the deployment code: OpenTofu fmt/contracts/validate, Ansible syntax and lint, no credentials needed
infra-check:
    ./infra/scripts/tofu-check.sh
    cd infra/ansible && ansible-galaxy role install -r requirements.yml -p galaxy_roles && ansible-galaxy collection install -r requirements.yml -p collections
    cd infra/ansible && ansible-playbook -i inventory/onprem.ini.example --syntax-check site.yml deploy.yml backup.yml restore.yml
    cd infra/ansible && ANSIBLE_COLLECTIONS_PATH=$PWD/collections uvx --from ansible-lint==26.8.0 ansible-lint --offline site.yml deploy.yml backup.yml restore.yml roles/

# smoke checks against a deployed host, e.g. just prod-smoke shp.subhambhandari.com.np (add HEALTH_TOKEN=… for /v1/health)
prod-smoke domain layout="split":
    PLATFORM_DOMAIN={{domain}} HOST_LAYOUT={{layout}} SMOKE_DEV=0 ./infra/scripts/smoke.sh

# start the bundled mail server (profile `mail`), configure it and print its DNS records (docker.md §Mail)
mail-init:
    ./infra/scripts/mail-init.sh

# send a test message with the ticket mail headers; via=stalwart submits through the bundled server (relayed to Mailpit in dev)
mail-send-test to="test@example.com" via="default":
    #!/usr/bin/env bash
    set -euo pipefail
    if [ "{{via}}" = stalwart ]; then
      password="$(sed -n 's/^MAIL_APP_PASSWORD=//p' .env | tail -n 1)"
      domain="$(sed -n 's/^PLATFORM_DOMAIN=//p' .env | tail -n 1)"
      docker compose exec -T -e MAIL_MAILER=smtp -e MAIL_HOST=mail -e MAIL_PORT=587 -e "MAIL_USERNAME=app@${domain}" -e "MAIL_PASSWORD=${password}" \
        app php artisan mail:send-test "{{to}}"
    else
      docker compose exec -T app php artisan mail:send-test "{{to}}"
    fi

# verify the DKIM signature of the newest message in Mailpit with the key from backend/.env (MAIL_DKIM_SELECTOR, MAIL_DKIM_PUBLIC_KEY)
mail-dkim-check:
    #!/usr/bin/env bash
    set -euo pipefail
    selector="$(sed -n 's/^MAIL_DKIM_SELECTOR=//p' backend/.env | tail -n 1)"
    key="$(sed -n 's/^MAIL_DKIM_PUBLIC_KEY=//p' backend/.env | tail -n 1)"
    [ -n "$selector" ] && [ -n "$key" ] || { echo "set MAIL_DKIM_SELECTOR and MAIL_DKIM_PUBLIC_KEY in backend/.env (printed by just mail-init)"; exit 1; }
    message="$(mktemp)"; trap 'rm -f "$message"' EXIT
    docker compose exec -T app curl -fsS http://mailpit:8025/api/v1/message/latest/raw > "$message"
    grep -m1 '^Subject:' "$message" || true
    uvx --from dkimpy==1.1.8 python infra/scripts/mail-dkim-verify.py "$message" "$selector" "$key"

# deliver an .eml file to the bundled mail server on port 25, as mail from outside arrives (needs `just mail-init`;
# mail:fetch-inbound picks it up within a minute when MAIL_INBOUND_ENABLED=true). Default: a new ticket for acme.
mail-inject file="backend/tests/Fixtures/inbound/plain-new-ticket.eml":
    sed "s/@shp\.example/@$(sed -n 's/^PLATFORM_DOMAIN=//p' .env | tail -n 1)/g" "{{file}}" | docker compose exec -T app php artisan mail:inject -

# answer a message from Mailpit as its recipient (In-Reply-To, References, quoted original), e.g. a public reply:
# just mail-reply <mailpit-id> "It works now." ; the id is in the Mailpit URL (latest = the newest message)
mail-reply id="latest" body="Thanks, that fixed it.":
    docker compose exec -T app sh -c 'curl -fsS "http://mailpit:8025/api/v1/message/{{id}}/raw" | php artisan mail:inject - --reply --body="$1"' _ "{{body}}"

# fetch the inbound mailbox now instead of waiting for the scheduler
mail-fetch:
    docker compose exec -T app php artisan mail:fetch-inbound
