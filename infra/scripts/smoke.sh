#!/usr/bin/env bash
# Smoke checks for a running stack (M1-04). Used locally (`just smoke`) and by the CI e2e job.
#   PLATFORM_DOMAIN  base domain (default shp.localhost)
#   SMOKE_INSECURE   1 = accept the Caddy internal CA (default 1 for *.localhost)
#   SMOKE_DEV        1 = also check dev-only tools (Mailpit, storage console, Horizon without auth)
set -uo pipefail

domain="${PLATFORM_DOMAIN:-shp.localhost}"
insecure="${SMOKE_INSECURE:-$([[ $domain == *localhost ]] && echo 1 || echo 0)}"
dev="${SMOKE_DEV:-$([[ $domain == *localhost ]] && echo 1 || echo 0)}"
curl_opts=(-s -o /dev/null --max-time 10 -w '%{http_code}')
[[ $insecure == 1 ]] && curl_opts+=(-k)

failures=0
check() { # check <expected-status-regex> <url> [extra curl args...]
  local expected="$1" url="$2"; shift 2
  local status
  status="$(curl "${curl_opts[@]}" "$@" "$url")"
  if [[ $status =~ ^($expected)$ ]]; then
    printf '  ok    %s %s\n' "$status" "$url"
  else
    printf '  FAIL  %s %s (expected %s)\n' "$status" "$url" "$expected"
    failures=$((failures + 1))
  fi
}

echo "Smoke checks for ${domain}"
check 200 "https://${domain}/"
check 200 "https://api.${domain}/up"
check 200 "https://api.${domain}/v1/ping"
check 200 "https://app.${domain}/acme"
check 200 "https://app.${domain}/config.json"
check 200 "https://admin.${domain}/"
check 200 "https://docs.${domain}/"
check 200 "https://files.${domain}/health/live"
check '403|404' "https://api.${domain}/api/v1/ping"   # no /api prefix on the api host
# Bucket CORS must allow the app origin (presigned uploads from the browser).
tls_flag=()
[[ $insecure == 1 ]] && tls_flag=(-k)
if curl -s "${tls_flag[@]}" --max-time 10 -o /dev/null -D - -X OPTIONS \
  "https://files.${domain}/helpdesk/smoke.txt" -H "Origin: https://app.${domain}" \
  -H 'Access-Control-Request-Method: PUT' | grep -qi "^access-control-allow-origin: https://app.${domain}"; then
  printf '  ok    CORS preflight from https://app.%s\n' "$domain"
else
  printf '  FAIL  CORS preflight from https://app.%s (run: php artisan storage:ensure-bucket)\n' "$domain"
  failures=$((failures + 1))
fi

if [[ $dev == 1 ]]; then
  check 200 "https://monitor.${domain}/horizon"
  check 200 "https://monitor.${domain}/rustfs/console/"
  check 200 "https://mail.${domain}/"
else
  check 401 "https://monitor.${domain}/horizon"   # basic auth in front of the consoles
fi

if [[ $failures -gt 0 ]]; then
  echo "${failures} check(s) failed"
  exit 1
fi
echo "All checks passed"
