#!/bin/sh
# Renders runtime configuration so one image works on any host (docs/03-architecture/frontend.md).
set -eu
: "${PLATFORM_DOMAIN:?PLATFORM_DOMAIN is required}"
: "${REALTIME_ENABLED:=false}"
: "${REVERB_APP_KEY:=}"
: "${TLS:=}"
if [ "${HOST_LAYOUT:-split}" = "single" ]; then
  : "${API_BASE_URL:=https://${PLATFORM_DOMAIN}/api}"
  : "${STORAGE_PUBLIC_ENDPOINT:=https://${PLATFORM_DOMAIN}/files}"
  # Reverb behind /api/app and /api/apps on the one host (Caddyfile.single).
  : "${REALTIME_HOST:=${PLATFORM_DOMAIN}}"
  : "${REALTIME_PATH:=/api}"
else
  : "${API_BASE_URL:=https://api.${PLATFORM_DOMAIN}}"
  : "${STORAGE_PUBLIC_ENDPOINT:=https://files.${PLATFORM_DOMAIN}}"
  # Reverb behind /app and /apps on the api host (Caddyfile).
  : "${REALTIME_HOST:=api.${PLATFORM_DOMAIN}}"
  : "${REALTIME_PATH:=}"
fi
export PLATFORM_DOMAIN REALTIME_ENABLED REVERB_APP_KEY REALTIME_HOST REALTIME_PATH API_BASE_URL STORAGE_PUBLIC_ENDPOINT TLS
mkdir -p /srv/runtime/tenant /srv/runtime/platform
APP_MODE=tenant envsubst < /etc/shp/config.template.json > /srv/runtime/tenant/config.json
APP_MODE=platform envsubst < /etc/shp/config.template.json > /srv/runtime/platform/config.json
if [ "${HOST_LAYOUT:-split}" = "single" ]; then
  cp /etc/caddy/Caddyfile.single /etc/caddy/Caddyfile
fi
exec "$@"
