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

test-frontend:
    pnpm -C frontend test

e2e:
    docker compose --profile dev --profile storage --profile demo --profile e2e up -d --wait
    docker compose run --rm playwright

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

reproduce seed="42":
    docker compose exec app php artisan experiment:run --all --seed={{seed}}
    python3 experiments/plots.py

report:
    cd report && ./build.sh

deploy env:
    cd infra/tofu/envs/{{env}} && tofu apply
    cd infra/ansible && ansible-playbook -i inventory/{{env}}.ini site.yml
