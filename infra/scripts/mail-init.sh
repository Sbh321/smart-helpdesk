#!/usr/bin/env sh
# Configure the bundled Stalwart mail server (ADR-0018, docs/09-infrastructure/docker.md §Mail).
#
#   just mail-init                      # development (root .env)
#   ./infra/scripts/mail-init.sh        # on a server, from /opt/smart-helpdesk (Ansible runs it)
#
# Idempotent: re-running converges the same state and prints the DNS records again.
#   1. starts the `mail` service (profile `mail`) and completes Stalwart's bootstrap on first boot
#      (server hostname, default domain, data store, stdout logs, DKIM keys);
#   2. applies a declarative plan with Stalwart's CLI: the `app` submission account, the `inbound`
#      mailbox as the domain's catch-all (ticket+<uuid>@ and support+<slug>@ land there), listeners
#      (25 inbound, 587 submission, 143 IMAP, 8080 admin/health; all but 25 stay on the internal
#      network; IMAP login without TLS for mail:fetch-inbound), SMTP auth on 587 only, the app may send
#      as any address of the domain, and the relay route when MAIL_RELAY_HOST is set (smart host for
#      port-25-blocked hosts);
#   3. keeps one RSA DKIM key with a fixed selector (no automatic rotation: DNS is published by hand);
#   4. prints the DNS records (MX, SPF, DKIM, DMARC) and the backend .env lines for Settings → Email.
#
# Settings come from the environment or the root .env (ENV_FILE):
#   PLATFORM_DOMAIN        mail domain unless MAIL_DOMAIN is set
#   MAIL_ADMIN_PASSWORD    Stalwart management credential (user `admin`, STALWART_RECOVERY_ADMIN)
#   MAIL_APP_PASSWORD      password of app@<domain>; Laravel's MAIL_PASSWORD when MAIL_HOST=mail
#   MAIL_INBOUND_PASSWORD  password of inbound@<domain> (IMAP, M3-19)
#   MAIL_RELAY_HOST/PORT/USERNAME/PASSWORD, MAIL_RELAY_IMPLICIT_TLS (true for port 465),
#   MAIL_RELAY_ALLOW_INVALID_CERTS    smart host; empty host = direct delivery to MX (needs port 25 out)
#   MAIL_SPF_INCLUDE       extra SPF include (e.g. the relay provider's) ; MAIL_DMARC_POLICY (none)
set -eu

cd "$(dirname "$0")/../.."
ENV_FILE=${ENV_FILE:-.env}

# Read KEY from the env file unless it is already in the environment (the file is not sourced:
# production .env files contain values that are not valid shell).
setting() {
  eval "current=\${$1-}"
  if [ -n "$current" ]; then printf '%s' "$current"; return; fi
  [ -f "$ENV_FILE" ] || return 0
  sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

fail() { echo "mail-init: $*" >&2; exit 1; }

PLATFORM_DOMAIN=$(setting PLATFORM_DOMAIN)
DOMAIN=$(setting MAIL_DOMAIN); DOMAIN=${DOMAIN:-$PLATFORM_DOMAIN}
[ -n "$DOMAIN" ] || fail "set PLATFORM_DOMAIN (or MAIL_DOMAIN) in $ENV_FILE"
MAIL_HOSTNAME=$(setting MAIL_HOSTNAME); MAIL_HOSTNAME=${MAIL_HOSTNAME:-mail.$DOMAIN}
ADMIN_PASSWORD=$(setting MAIL_ADMIN_PASSWORD)
APP_PASSWORD=$(setting MAIL_APP_PASSWORD)
INBOUND_PASSWORD=$(setting MAIL_INBOUND_PASSWORD)
RELAY_HOST=$(setting MAIL_RELAY_HOST)
RELAY_PORT=$(setting MAIL_RELAY_PORT); RELAY_PORT=${RELAY_PORT:-587}
RELAY_USERNAME=$(setting MAIL_RELAY_USERNAME)
RELAY_PASSWORD=$(setting MAIL_RELAY_PASSWORD)
RELAY_IMPLICIT_TLS=$(setting MAIL_RELAY_IMPLICIT_TLS); RELAY_IMPLICIT_TLS=${RELAY_IMPLICIT_TLS:-false}
RELAY_INVALID_CERTS=$(setting MAIL_RELAY_ALLOW_INVALID_CERTS); RELAY_INVALID_CERTS=${RELAY_INVALID_CERTS:-false}
SPF_INCLUDE=$(setting MAIL_SPF_INCLUDE)
DMARC_POLICY=$(setting MAIL_DMARC_POLICY); DMARC_POLICY=${DMARC_POLICY:-none}

# Values end up in JSON documents: keep them to characters that need no escaping.
safe() { printf '%s' "$2" | grep -Eq "^[A-Za-z0-9._~@+/=-]{$3,}$" || fail "$1 must be at least $3 characters of A-Z a-z 0-9 . _ ~ @ + / = -"; }
safe MAIL_ADMIN_PASSWORD "$ADMIN_PASSWORD" 8
safe MAIL_APP_PASSWORD "$APP_PASSWORD" 8
safe MAIL_INBOUND_PASSWORD "$INBOUND_PASSWORD" 8
safe MAIL_DOMAIN "$DOMAIN" 3
safe MAIL_HOSTNAME "$MAIL_HOSTNAME" 3
case "$RELAY_PORT" in *[!0-9]*) fail "MAIL_RELAY_PORT must be a number" ;; esac
for flag in "$RELAY_IMPLICIT_TLS" "$RELAY_INVALID_CERTS"; do
  case "$flag" in true | false) ;; *) fail "MAIL_RELAY_IMPLICIT_TLS and MAIL_RELAY_ALLOW_INVALID_CERTS are true or false" ;; esac
done
if [ -n "$RELAY_HOST" ]; then
  safe MAIL_RELAY_HOST "$RELAY_HOST" 1
  [ -z "$RELAY_USERNAME" ] || safe MAIL_RELAY_USERNAME "$RELAY_USERNAME" 1
  [ -z "$RELAY_PASSWORD" ] || safe MAIL_RELAY_PASSWORD "$RELAY_PASSWORD" 1
fi

compose() { MAIL_ADMIN_PASSWORD=$ADMIN_PASSWORD docker compose --progress quiet --profile mail "$@"; }
cli() { MAIL_ADMIN_PASSWORD=$ADMIN_PASSWORD docker compose --progress quiet run --rm -T --no-deps mail-cli "$@"; }

# JMAP call against the management API from inside the container (no port is published for it).
jmap() {
  compose exec -T mail curl -s -u "admin:$ADMIN_PASSWORD" -H 'Content-Type: application/json' --data-binary @- \
    http://127.0.0.1:8080/jmap/
}

wait_healthy() {
  i=0
  until compose exec -T mail curl -fsS -o /dev/null http://127.0.0.1:8080/healthz/live 2>/dev/null; do
    i=$((i + 1)); [ "$i" -lt 60 ] || fail "the mail service did not become healthy"; sleep 2
  done
}

echo "mail-init: starting the mail service for $DOMAIN ($MAIL_HOSTNAME)"
compose up -d mail
wait_healthy

# ---- 1. bootstrap (first boot only: no config.json yet) ----
state=$(printf '%s' '{"using":["urn:ietf:params:jmap:core","urn:stalwart:jmap"],"methodCalls":[["x:Bootstrap/get",{"ids":["singleton"]},"0"]]}' | jmap)
case "$state" in
  *'"serverHostname"'*)
    echo "mail-init: completing Stalwart's bootstrap"
    result=$(jmap <<JSON
{"using":["urn:ietf:params:jmap:core","urn:stalwart:jmap"],"methodCalls":[["x:Bootstrap/set",{"update":{"singleton":{
  "serverHostname":"$MAIL_HOSTNAME","defaultDomain":"$DOMAIN","requestTlsCertificate":false,"generateDkimKeys":true,
  "dataStore":{"@type":"RocksDb","path":"/var/lib/stalwart/data/"},
  "tracer":{"@type":"Stdout","level":"info","enable":true}}}},"0"]]}
JSON
)
    case "$result" in *'"updated"'*) ;; *) fail "bootstrap failed: $result" ;; esac
    compose restart mail >/dev/null
    wait_healthy
    ;;
  *'"notFound"'*) ;;
  *) fail "cannot reach Stalwart's management API as admin (check MAIL_ADMIN_PASSWORD): $state" ;;
esac

# ---- 2. declarative plan ----
# MVP-SHORTCUT: submission on 587 without TLS; it is reachable only on the Docker network; V1: V1-ML-06.
# MVP-SHORTCUT: IMAP on 143 accepts a password without TLS for mail:fetch-inbound, internal network only; V1: V1-ML-06.
if [ -n "$RELAY_HOST" ]; then
  if [ -n "$RELAY_USERNAME" ]; then
    relay_auth="\"authUsername\":\"$RELAY_USERNAME\",\"authSecret\":{\"@type\":\"Value\",\"secret\":\"$RELAY_PASSWORD\"}"
  else
    relay_auth='"authUsername":null,"authSecret":{"@type":"None"}'
  fi
  relay_route="{\"@type\":\"upsert\",\"object\":\"MtaRoute\",\"matchOn\":[\"name\"],\"value\":{\"relay\":{\"@type\":\"Relay\",\"name\":\"relay\",\"description\":\"Smart host (MAIL_RELAY_HOST)\",\"address\":\"$RELAY_HOST\",\"port\":$RELAY_PORT,\"protocol\":\"smtp\",\"implicitTls\":$RELAY_IMPLICIT_TLS,\"allowInvalidCerts\":$RELAY_INVALID_CERTS,$relay_auth}}}"
  remote_route="'relay'"
else
  relay_route='{"@type":"destroy","object":"MtaRoute","value":{"name":"relay"}}'
  remote_route="'mx'"
fi

echo "mail-init: applying accounts, listeners, auth and routing (remote route: $remote_route)"
cli apply --stdin --quiet <<PLAN
{"@type":"upsert","object":"Domain","matchOn":["name"],"value":{"domain":{"name":"$DOMAIN","catchAllAddress":"inbound@$DOMAIN","subAddressing":{"@type":"Enabled"},"dkimManagement":{"@type":"Manual"},"reportAddressUri":"mailto:postmaster@$DOMAIN"}}}
{"@type":"upsert","object":"Account","matchOn":["name"],"value":{"app":{"@type":"User","name":"app","domainId":"#domain","description":"Smart Helpdesk application (SMTP submission)","credentials":{"0":{"@type":"Password","secret":"$APP_PASSWORD"}}}}}
{"@type":"upsert","object":"Account","matchOn":["name"],"value":{"inbound":{"@type":"User","name":"inbound","domainId":"#domain","description":"Inbound mailbox and catch-all (IMAP, mail:fetch-inbound)","credentials":{"0":{"@type":"Password","secret":"$INBOUND_PASSWORD"}}}}}
{"@type":"reconcile","object":"NetworkListener","matchOn":["name"],"value":{"smtp":{"name":"smtp","protocol":"smtp","bind":{"[::]:25":true},"useTls":true,"tlsImplicit":false},"submission":{"name":"submission","protocol":"smtp","bind":{"[::]:587":true},"useTls":false,"tlsImplicit":false},"imap":{"name":"imap","protocol":"imap","bind":{"[::]:143":true},"useTls":false,"tlsImplicit":false},"http":{"name":"http","protocol":"http","bind":{"[::]:8080":true},"useTls":false,"tlsImplicit":false}}}
{"@type":"update","object":"Imap","value":{"allowPlainTextAuth":true}}
{"@type":"update","object":"MtaStageAuth","value":{"saslMechanisms":{"match":{"0":{"if":"local_port == 587","then":"[plain, login]"}},"else":"false"},"require":{"match":{},"else":"local_port == 587"},"mustMatchSender":{"match":{"0":{"if":"authenticated_as == 'app@$DOMAIN'","then":"false"}},"else":"true"}}}
$relay_route
{"@type":"update","object":"MtaOutboundStrategy","value":{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"'local'"}},"else":"$remote_route"}}}
PLAN

# ---- 3. one RSA DKIM key with a stable selector ----
# MVP-SHORTCUT: no rotation (the Domain's dkimManagement is Manual) because DNS is published by hand; V1: V1-ML-05.
signatures=$(cli query DkimSignature --json)
for id in $(printf '%s\n' "$signatures" | grep 'Ed25519' | sed -n 's/.*"id":"\([^"]*\)".*/\1/p'); do
  cli delete DkimSignature --ids "$id" >/dev/null
done
rsa_id=$(printf '%s\n' "$signatures" | grep 'Dkim1RsaSha256' | head -n 1 | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
[ -n "$rsa_id" ] || fail "no RSA DKIM key for $DOMAIN (Stalwart generates it on the first start after bootstrap)"
key=$(cli get DkimSignature "$rsa_id" --fields selector,publicKey --json)
SELECTOR=$(printf '%s' "$key" | sed -n 's/.*"selector":"\([^"]*\)".*/\1/p')
PUBLIC_KEY=$(printf '%s' "$key" | sed -n 's/.*"publicKey":"\([^"]*\)".*/\1/p' | sed -e 's/\\n//g' -e 's/-----[A-Z ]*-----//g')

# Listener changes take effect on restart.
compose restart mail >/dev/null
wait_healthy

# ---- 4. DNS records ----
spf="v=spf1 mx${SPF_INCLUDE:+ include:$SPF_INCLUDE} -all"
echo
echo "mail-init: done. Publish these DNS records for $DOMAIN (docs/09-infrastructure/production.md §DNS):"
echo
record() { printf '  %-4s %-44s %s\n' "$1" "$2" "$3"; }
record MX "$DOMAIN." "10 $MAIL_HOSTNAME."
record A "$MAIL_HOSTNAME." "<server address> (and PTR <server address> -> $MAIL_HOSTNAME)"
record TXT "$DOMAIN." "\"$spf\""
record TXT "$SELECTOR._domainkey.$DOMAIN." "\"v=DKIM1; k=rsa; h=sha256; p=$PUBLIC_KEY\""
record TXT "_dmarc.$DOMAIN." "\"v=DMARC1; p=$DMARC_POLICY; rua=mailto:postmaster@$DOMAIN\""
cat <<EOF

Backend .env (Settings → Email shows the same records):

  MAIL_DKIM_SELECTOR=$SELECTOR
  MAIL_DKIM_PUBLIC_KEY=$PUBLIC_KEY

Laravel submits through the bundled server with:
  MAIL_MAILER=smtp  MAIL_HOST=mail  MAIL_PORT=587  MAIL_USERNAME=app@$DOMAIN  MAIL_PASSWORD=<MAIL_APP_PASSWORD>
EOF
if [ -n "$RELAY_HOST" ]; then
  echo "Outbound mail to other domains is relayed through $RELAY_HOST:$RELAY_PORT (still DKIM-signed for $DOMAIN)."
else
  echo "Outbound mail goes directly to the recipients' MX hosts: the server needs outbound port 25 (otherwise set MAIL_RELAY_HOST)."
fi
