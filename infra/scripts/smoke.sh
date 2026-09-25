#!/usr/bin/env bash
# Smoke checks for a running stack: the dev stack (`just smoke`), the CI e2e job and a deployed
# production host (M3-14, docs/09-infrastructure/production.md §Smoke test).
#   PLATFORM_DOMAIN  base domain (default shp.localhost)
#   HOST_LAYOUT      split (default) | single (on-prem single-host mode: /api, /files, /monitor paths)
#   SMOKE_INSECURE   1 = accept the Caddy internal CA (default 1 for *.localhost)
#   SMOKE_DEV        1 = also check dev-only tools (Mailpit, storage console, Horizon without auth)
#   SMOKE_STORAGE    1 = check the bundled RustFS endpoint and bucket CORS (default 1)
#   SMOKE_CONNECT    host:port to connect to instead of DNS + 443, e.g. 127.0.0.1:18443 for a test
#                    stack on another port, or the VM address before DNS exists (Host/SNI stay the same)
#   SMOKE_WORKSPACE  workspace slug for the SPA deep link (default acme)
#   HEALTH_TOKEN     when set, /v1/health must answer 200 with the bearer token
#   SMOKE_EMAIL, SMOKE_PASSWORD
#                    when set, sign in to SMOKE_WORKSPACE through the SPA flow (CSRF cookie + login + /v1/me)
set -uo pipefail

domain="${PLATFORM_DOMAIN:-shp.localhost}"
layout="${HOST_LAYOUT:-split}"
insecure="${SMOKE_INSECURE:-$([[ $domain == *localhost ]] && echo 1 || echo 0)}"
dev="${SMOKE_DEV:-$([[ $domain == *localhost ]] && echo 1 || echo 0)}"
storage="${SMOKE_STORAGE:-1}"
workspace="${SMOKE_WORKSPACE:-acme}"

base_opts=(-s --max-time 15)
[[ $insecure == 1 ]] && base_opts+=(-k)
[[ -n ${SMOKE_CONNECT:-} ]] && base_opts+=(--connect-to "::${SMOKE_CONNECT}")
curl_opts=("${base_opts[@]}" -o /dev/null -w '%{http_code}')

failures=0
ok() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
check() { # check <expected-status-regex> <url> [extra curl args...]
  local expected="$1" url="$2"; shift 2
  local status
  status="$(curl "${curl_opts[@]}" "$@" "$url")"
  if [[ $status =~ ^($expected)$ ]]; then ok "$status $url"; else fail "$status $url (expected $expected)"; fi
}

if [[ $layout == single ]]; then
  site="https://${domain}"; api="${site}/api"; app="${site}"; files="${site}/files"; monitor="${site}/monitor"
else
  site="https://${domain}"; api="https://api.${domain}"; app="https://app.${domain}"
  files="https://files.${domain}"; monitor="https://monitor.${domain}"
fi

echo "Smoke checks for ${domain} (${layout} layout${SMOKE_CONNECT:+, via ${SMOKE_CONNECT}})"
check 200 "${site}/"
check 200 "${api}/up"
check 200 "${api}/v1/ping"
check 200 "${app}/config.json"
if [[ $layout == single ]]; then
  check 200 "${site}/${workspace}"
  check 302 "${site}/docs/api"   # the API reference needs a sign-in (M3-06)
else
  check 200 "${app}/${workspace}"
  check 200 "https://admin.${domain}/"
  check 302 "https://docs.${domain}/"   # the API reference needs a sign-in (M3-06)
  check 302 "https://platform-docs.${domain}/"   # the platform docs need the platform sign-in (M5-06)
  check '403|404' "${api}/api/v1/ping"   # no /api prefix on the api host
fi
check 401 "${api}/v1/me" -H 'Accept: application/json'   # protected routes need a session or token

# Security headers from the proxy (docs/03-architecture/security.md).
headers="$(curl "${base_opts[@]}" -o /dev/null -D - "${app}/config.json")"
for h in strict-transport-security x-content-type-options referrer-policy; do
  if grep -qi "^${h}:" <<<"$headers"; then ok "header ${h}"; else fail "header ${h} missing"; fi
done
if grep -qi '^server:' <<<"$headers"; then fail "Server header present"; else ok "no Server header"; fi

if [[ $storage == 1 ]]; then
  check 200 "${files}/health/live"
  # Bucket CORS must allow the app origin (presigned uploads from the browser).
  origin="${app%/}"
  if curl "${base_opts[@]}" -o /dev/null -D - -X OPTIONS "${files}/helpdesk/smoke.txt" \
    -H "Origin: ${origin}" -H 'Access-Control-Request-Method: PUT' |
    grep -qi "^access-control-allow-origin: ${origin}"; then
    ok "CORS preflight from ${origin}"
  else
    fail "CORS preflight from ${origin} (run: php artisan storage:ensure-bucket)"
  fi
fi

if [[ $layout == split ]]; then
  # Monitoring needs the platform pass from the console (ADR-0024): without it every page redirects there.
  check 302 "${monitor}/horizon"
  check 302 "${monitor}/health"
  check 302 "https://monitor.${domain}/rustfs/console/"
fi
if [[ $dev == 1 ]]; then
  check 200 "https://mail.${domain}/"
fi

if [[ -n ${HEALTH_TOKEN:-} ]]; then
  body="$(curl "${base_opts[@]}" -H "Authorization: Bearer ${HEALTH_TOKEN}" -H 'Accept: application/json' \
    -w '\n%{http_code}' "${api}/v1/health")"
  status="${body##*$'\n'}"
  if [[ $status == 200 ]]; then
    ok "200 ${api}/v1/health"
  else
    # Name the failing checks (spatie/laravel-health report) instead of printing the whole document.
    failed="$(grep -o '"name":"[A-Za-z]*","label":"[^"]*","status":"failed","summary":"[^"]*","message":"[^"]*"' <<<"${body%$'\n'*}" |
      sed -E 's/.*"name":"([^"]*)".*"message":"([^"]*)"/\1 (\2)/' | paste -sd ';' -)"
    fail "${status} ${api}/v1/health: ${failed:-${body%$'\n'*}}"
  fi
fi

if [[ -n ${SMOKE_EMAIL:-} && -n ${SMOKE_PASSWORD:-} ]]; then
  jar="$(mktemp)"; trap 'rm -f "$jar"' EXIT
  curl "${base_opts[@]}" -c "$jar" -b "$jar" -o /dev/null -H "Origin: ${app%/}" -H "Referer: ${app}/" "${api}/sanctum/csrf-cookie"
  xsrf="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar" | tail -1)"
  xsrf="$(printf '%b' "${xsrf//%/\\x}")"
  status="$(curl "${curl_opts[@]}" -c "$jar" -b "$jar" -X POST "${api}/v1/auth/login" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Origin: ${app%/}" -H "Referer: ${app}/" \
    -H "X-XSRF-TOKEN: ${xsrf}" \
    --data "{\"workspace\":\"${workspace}\",\"email\":\"${SMOKE_EMAIL}\",\"password\":\"${SMOKE_PASSWORD}\"}")"
  if [[ $status =~ ^20[04]$ ]]; then ok "${status} sign-in as ${SMOKE_EMAIL}"; else fail "${status} sign-in as ${SMOKE_EMAIL}"; fi
  check 200 "${api}/v1/me" -c "$jar" -b "$jar" -H 'Accept: application/json' -H "Origin: ${app%/}" -H "Referer: ${app}/"
fi

if [[ $failures -gt 0 ]]; then
  echo "${failures} check(s) failed"
  exit 1
fi
echo "All checks passed"
