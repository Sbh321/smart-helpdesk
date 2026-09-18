#!/usr/bin/env bash
# First-time setup (docs/09-infrastructure/local-development.md §First run).
set -euo pipefail
cd "$(dirname "$0")/../.."

[ -f .env ] || { cp .env.example .env; echo "created .env from .env.example"; }

mkdir -p secrets
for name in db_superuser_password db_owner_password db_app_password db_backup_password; do
  [ -s "secrets/$name" ] || { openssl rand -hex 24 > "secrets/$name"; chmod 600 "secrets/$name"; echo "generated secrets/$name"; }
done

if [ ! -f backend/artisan ]; then
  echo "backend is not scaffolded yet (roadmap task M1-02); stopping after secrets."
  exit 0
fi

[ -f backend/.env ] || { cp backend/.env.example backend/.env; echo "created backend/.env from backend/.env.example"; }

docker compose --profile dev --profile storage up -d --wait --build
grep -q '^APP_KEY=base64:' backend/.env || docker compose exec -T app php artisan key:generate --force --no-interaction
docker compose exec -T -e DB_CONNECTION=pgsql_owner app php artisan migrate --force
docker compose exec -T app php artisan storage:ensure-bucket
if [ -f backend/database/seeders/DemoSeeder.php ]; then
  docker compose exec -T app php artisan db:seed --class=DemoSeeder --force
else
  echo "DemoSeeder does not exist yet (roadmap M2); skipping demo data."
fi
docker compose exec -T app php artisan scramble:export --path=openapi.json
pnpm -C frontend install
pnpm -C frontend api:types
./infra/scripts/smoke.sh

cat <<'URLS'
Ready:
  https://app.shp.localhost/acme      workspace (priya@acme.test / password)
  https://api.shp.localhost/v1/ping   API
  https://admin.shp.localhost         platform admin (admin@platform.test / password)
  https://monitor.shp.localhost       Horizon, health, storage and mail consoles
  https://docs.shp.localhost          API reference
  https://mail.shp.localhost          Mailpit
URLS
