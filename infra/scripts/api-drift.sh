#!/usr/bin/env bash
# Contract drift check (M1-13, docs/07-api/documentation.md): regenerates the OpenAPI document and the
# typed client schema, then fails when either differs from what is committed.
# Locally: `just api-drift` (the stack must be up). In CI: after migrations, with the app container running.
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root" || exit 1

document="backend/openapi.json"
types="frontend/src/lib/api/schema.d.ts"

echo "==> exporting $document"
docker compose exec -T app php artisan scramble:export --path=openapi.json || exit 1

echo "==> generating $types"
pnpm -C frontend api:types || exit 1

status=0
for file in "$document" "$types"; do
  if ! git ls-files --error-unmatch "$file" >/dev/null 2>&1; then
    printf '  note  %s is not committed yet; commit it so drift becomes a reviewable diff\n' "$file"
    continue
  fi
  if git diff --exit-code --stat -- "$file" >/dev/null; then
    printf '  ok    %s is up to date\n' "$file"
  else
    printf '  DRIFT %s changed; commit the regenerated file\n' "$file"
    git --no-pager diff -- "$file" | head -60
    status=1
  fi
done

if [[ $status -ne 0 ]]; then
  echo "API contract drift: run 'just api-docs' and commit the result."
fi
exit $status
