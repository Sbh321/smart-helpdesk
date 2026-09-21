# Webhooks

Outbound HTTP notifications for tenant integrations. Domain model: [04-domain/integrations.md](../04-domain/integrations.md). Module: `Integrations` (`WebhookSubscription`, `WebhookDelivery`, `DispatchWebhookEvent` listener, `DeliverWebhook` job, `WebhookSigner`).

## Subscription model

| Field | Notes |
|---|---|
| `id`, `tenant_id` | UUID v7; tenant-scoped |
| `name`, `url` | `https://` only in production (`http://` allowed for `webhook-echo` in dev/demo profiles) |
| `events[]` | subset of the catalogue; `*` not allowed (explicit list) |
| `secret` | 32 random bytes, base64; shown once; stored with the `encrypted` cast; rotatable (`/rotate-secret` keeps the old secret valid for 24 h, sending both signatures) |
| `api_version` | `v1` |
| `is_active`, `disabled_at`, `disabled_reason` | manual disable or auto-disable |
| `consecutive_failures` | reset on any success |
| `headers` | optional static headers (e.g. `Authorization`) stored encrypted, max 5 |

Managed via `/v1/webhooks` ([conventions.md](conventions.md)); creating, editing, disabling and rotating are audited.

## Event catalogue (v1)

| Event | Emitted when | `data` |
|---|---|---|
| `ticket.created` | after commit of `CreateTicket` | ticket resource (`include=contact,category,assignee`) |
| `ticket.updated` | fields edited via PATCH | ticket + `changes` map |
| `ticket.assigned` | team/agent set or changed | ticket + `assignment` (agent, team, reason) |
| `ticket.status_changed` | any transition | ticket + `from`, `to` |
| `ticket.priority_changed` | level change (auto or override) | ticket + `from`, `to`, `reason` |
| `ticket.resolved`, `ticket.closed` | those transitions (also emitted as `status_changed`) | ticket |
| `ticket.comment_added` | **public** comments only | comment + `ticket_id` |
| `ticket.sla_breached` | timer breach | ticket + `timer` (kind, due_at, breached_at) |
| `contact.created`, `contact.updated` | | contact resource |

Internal notes, notifications and platform events are never delivered.

## Payload envelope

```json
{
  "id": "019a1f2f-5b7c-7e1d-8c3a-1a2b3c4d5e6f",
  "type": "ticket.status_changed",
  "api_version": "v1",
  "tenant_id": "019a1e00-…",
  "occurred_at": "2026-09-17T10:15:32Z",
  "data": {
    "ticket": { "id": "019a…", "number": 1042, "status": "resolved", "…": "…" },
    "from": "in_progress",
    "to": "resolved"
  }
}
```

`id` is the event id (stable across retries); `data.<resource>` reuses the REST resource shape of `api_version`.

## Signing

Headers on every delivery:

| Header | Value |
|---|---|
| `X-Helpdesk-Signature` | `v1=<hex HMAC-SHA256>` (two `v1=` entries during secret rotation, comma-separated) |
| `X-Helpdesk-Timestamp` | Unix seconds when the request was signed (per attempt) |
| `X-Helpdesk-Event-Id` | event `id` |
| `X-Helpdesk-Event-Type` | event `type` |
| `X-Helpdesk-Delivery-Id` | delivery attempt id |
| `User-Agent` | `SmartHelpdesk-Webhooks/1.0` |
| `Content-Type` | `application/json` |

Signature input is `"{timestamp}.{raw_body}"`; the body is the exact bytes sent (no re-serialisation on the receiver side). The HMAC key is the secret string exactly as the API showed it (the base64 text, not its decoded bytes).

Receiver verification (Node):

```js
import { createHmac, timingSafeEqual } from 'node:crypto';
export function verify(rawBody, headers, secret, toleranceSec = 300) {
  const ts = Number(headers['x-helpdesk-timestamp']);
  if (!ts || Math.abs(Date.now() / 1000 - ts) > toleranceSec) return false;
  const expected = createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex');
  return headers['x-helpdesk-signature'].split(',').some(part => {
    const sig = part.trim().replace(/^v1=/, '');
    return sig.length === expected.length && timingSafeEqual(Buffer.from(sig, 'hex'), Buffer.from(expected, 'hex'));
  });
}
```

Receiver verification (PHP):

```php
function verify(string $rawBody, array $headers, string $secret, int $tolerance = 300): bool {
    $ts = (int) ($headers['X-Helpdesk-Timestamp'] ?? 0);
    if ($ts === 0 || abs(time() - $ts) > $tolerance) return false;
    $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
    foreach (explode(',', $headers['X-Helpdesk-Signature'] ?? '') as $part) {
        if (hash_equals($expected, substr(trim($part), 3))) return true;
    }
    return false;
}
```

Replay protection: receivers reject timestamps older than 300 s and deduplicate on `X-Helpdesk-Event-Id` (retries reuse the event id with a fresh timestamp and signature).

## Delivery

```mermaid
sequenceDiagram
    participant Act as Action (e.g. TransitionTicket)
    participant Ev as Event bus
    participant L as DispatchWebhookEvent (queued listener)
    participant DB
    participant J as DeliverWebhook job (webhooks queue)
    participant R as Receiver
    Act->>Ev: TicketStatusChanged (after commit)
    Ev->>L: handle (tenant context restored)
    L->>DB: for each active subscription with this event: insert webhook_deliveries {state: pending, attempt: 0, payload}
    L->>J: dispatch per delivery
    J->>R: POST url (10 s timeout, signed)
    alt 2xx
        J->>DB: state=succeeded, response_status, consecutive_failures=0
    else non-2xx / timeout / DNS error
        J->>DB: attempt++, response excerpt, next_attempt_at = now + backoff
        J->>J: release with delay (or state=dead after attempt 5)
    end
```

| Rule | Value |
|---|---|
| Success | any `2xx` within 10 s; body ignored (first 1 KB stored as `response_excerpt`) |
| Failure | non-2xx, timeout, TLS/DNS error, redirect (redirects are **not** followed) |
| Backoff | attempts at +1 m, +5 m, +30 m, +2 h, +12 h, each with ±20 % jitter; after the 5th failed attempt state `dead` |
| Auto-disable | 20 consecutive failed deliveries (across events) → `is_active=false`, `disabled_reason=consecutive_failures`, notification to tenant admins, audit entry |
| Manual retry | `POST /webhook-deliveries/{id}/retry` on `failed`/`dead` → new attempt sequence (attempt counter continues; max 5 manual retries) |
| Test | `POST /webhooks/{id}/test` sends `ping` event `{ "type": "ping", "data": { "message": "…" } }` synchronously-queued, returns 202 with the delivery id |
| Ordering | not guaranteed across events; receivers must use `occurred_at` and resource state, not arrival order |
| Retention | deliveries pruned after 30 days (`webhooks:prune` daily at 03:40); subscriptions keep aggregate counters |
| Payload size | ≤ 256 KB; larger `data` is trimmed to ids with `data.truncated=true` |
| Concurrency | `webhooks` queue, Horizon supervisor with 4 processes; `WithoutOverlapping` keyed by subscription id to keep per-endpoint order best-effort |

Job settings: `$tries = 1` per attempt (retries are explicit via `release`), `$timeout = 15`, `$backoff` unused, tags `tenant:{id}`, `webhook:{subscription_id}`.

## SSRF protection

On create/update and again immediately before each send (DNS may change):

1. Scheme `https` (production) with a valid hostname; no userinfo, no ports other than 443/8443 (configurable).
2. Resolve all A/AAAA records; reject loopback, link-local, private (RFC 1918, ULA), multicast, cloud metadata (`169.254.169.254`), and the platform's own hosts.
3. Connect to the resolved IP with `Host` header pinned (Guzzle `force_ip_resolve` + `curl.resolve` option) so a DNS rebinding between check and send cannot redirect the request.
4. Redirects disabled; response body capped at 1 KB read.
5. `webhook_url_rejected` (422) at configuration time; `failed` with `reason=url_rejected` at send time.

## Tenancy

Subscriptions and deliveries carry `tenant_id` and are under RLS; the listener runs with the originating tenant initialised (queue bootstrapper); payloads are built from tenant-scoped resources, so a subscription can never receive another tenant's data. Secrets are per subscription.

## Receiver checklist (published in the developer docs)

Verify signature → check timestamp → deduplicate on event id → respond `2xx` fast (enqueue work) → fetch the resource via the REST API when full state is needed → tolerate unknown event types and fields.

## As built (M3-05)

Code: `backend/app/Modules/Integrations` (`Domain/Webhooks`, `Webhooks/`, `Actions/*Webhook*`, `Jobs/DeliverWebhook`,
`Listeners/DispatchWebhookEvent`, `Console/*Webhook*`), SPA `frontend/src/features/integrations`
(Settings → Webhooks), receiver `tools/webhook-echo`.

**API.** `GET/POST /v1/webhooks`, `GET /v1/webhooks/events` (the catalogue without `ping`),
`GET/PATCH/DELETE /v1/webhooks/{webhook}`, `POST /v1/webhooks/{webhook}/enable|disable|rotate-secret|test`,
`GET /v1/webhooks/{webhook}/deliveries` (cursor, `per_page` ≤ 100, `filter[state]`),
`GET /v1/webhook-deliveries/{delivery}` (with the payload), `POST /v1/webhook-deliveries/{delivery}/retry`.
All need `integrations.manage` and are the only routes open to API clients with the `webhooks:manage`
scope. `secret` appears only in the create and rotate responses. Test deliveries: 10 a minute per
workspace. Create, edit, enable, disable, rotate and delete are audited (`webhook.*`); the audit entry
never holds a secret. Errors: 422 `webhook_url_rejected` (`meta.reason`: `invalid_url`, `scheme`,
`userinfo`, `port`, `platform_host`, `unresolvable`, `private_address`; field error on `url`),
409 `delivery_not_retryable` (`meta.reason`: `state`, `subscription_disabled`, `manual_retries_exhausted`).

**Storage.** `webhook_subscriptions` (secret and previous secret with the `encrypted` cast, `events text[]`
with a non-empty check) and `webhook_deliveries` (payload as `json` so the envelope keeps its member
order; `(tenant_id, subscription_id)` composite foreign key; partial index for the retry sweep). Both are
in `TenantTables::PRIMARY` with an immutable `tenant_id`; neither has the change-capture trigger
(configuration is audited, the delivery log is read directly by RPT-I01).

**Event mapping** (`DispatchWebhookEvent`): `TicketCreated` → `ticket.created` (deferred to after
commit), `TicketUpdated` → `ticket.updated` (`changes`), `TicketAssigned` → `ticket.assigned`
(`assignment.agent_id`, `team_id`, `reason` from the latest assignment row), `TicketStatusChanged` →
`ticket.status_changed` (`from`, `to`) plus `ticket.resolved` or `ticket.closed`, `PriorityChanged` →
`ticket.priority_changed` (`from`, `to`, `reason`: `override` or `automatic`), `CommentAdded` (public
only) → `ticket.comment_added` (`comment`, `ticket_id`), `SlaBreached` → `ticket.sla_breached`
(`timer`: `id`, `kind`, `due_at`, `breached_at`), `ContactSaved` (new, raised by `SaveContact` when
something changed) → `contact.created` / `contact.updated`. `data.ticket` is the `TicketResource`
with contact, organisation, category and tags, resolved without a user (`allowed_transitions` empty).

**Delivery as built.**

- The listener runs synchronously in the request or job that raised the event (after commit): it builds
  the payload once, inserts one `pending` delivery per listening subscription and queues one
  `DeliverWebhook` per delivery on `webhooks`. Only the HTTP call is queued. A failure in the listener is
  reported and never reaches the user.
- `DeliverWebhook` claims the delivery (`pending` and due) by moving `next_attempt_at` a 60-second lease
  ahead, so a duplicate job does nothing. It re-runs the SSRF guard, signs, and POSTs with a 10 s timeout
  (5 s connect), redirects off, the connection pinned to the checked address (`CURLOPT_RESOLVE`), and
  reads at most 1 KB of the response.
- Retries are not job releases: a failed attempt stores `state=failed` and `next_attempt_at`, and
  `webhooks:retry-due` (every minute) queues due attempts per workspace, so the schedule runs on `Clock`
  time and is testable with `FrozenClock`. One first attempt plus retries at +1 m, +5 m, +30 m, +2 h,
  +12 h (±20 % jitter): the sixth failed attempt makes the delivery `dead`. The sweep also re-queues a
  `pending` delivery whose job was lost once it is five minutes overdue.
- Auto-disable counts consecutive failed **attempts** (any success resets it); at 20 the subscription is
  disabled with `disabled_reason=consecutive_failures` and a `webhook.disabled` audit entry with actor
  `system`. No admin notification yet (`MVP-SHORTCUT`, V1-DP-06); the state shows in Settings → Webhooks.
- Deliveries of a disabled subscription are marked `dead` (`error=subscription_disabled`) when their
  attempt comes up; a test `ping` is sent even to a disabled subscription and is never retried
  automatically.
- Manual retry reopens a `failed` or `dead` delivery as a new sequence with the full schedule while the
  subscription is enabled; `attempt` keeps counting, at most 5 manual retries.
- Errors stored on a delivery: `http_status`, `redirect_not_followed`, `timeout`, `connection_failed`,
  `request_failed`, `url_rejected: <reason>`, `subscription_disabled`.
- Horizon: `supervisor-webhooks`, 2 processes locally, 4 in production, job timeout 15 s.

**SSRF guard as built** (`UrlGuard`, `IpAddressPolicy`): scheme `https`, no user info, ports 443/8443
(`helpdesk.webhooks.allowed_ports`), not a platform host (`PLATFORM_DOMAIN` and its subdomains, the
configured hosts, `localhost`, `*.localhost`), every A/AAAA record (or the IP literal) public. Refused:
0/8, 10/8, 100.64/10, 127/8, 169.254/16 (metadata), 172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24,
192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4; `::`, `::1`, `100::/64`, `2001::/23`,
`2001:db8::/32`, `fc00::/7`, `fe80::/10`, `fec0::/10`, `ff00::/8`; and IPv4 embedded in IPv6 (mapped,
compatible, NAT64 `64:ff9b::/96`, 6to4 `2002::/16`) is checked as IPv4. Development only:
`helpdesk.webhooks.dev_allowed_hosts` (`WEBHOOK_DEV_ALLOWED_HOSTS`, host names, not ranges) skips the
scheme, port and address checks so `http://webhook-echo:9100/hook` can be used; it is ignored when
`APP_ENV=production`.

**Verification snippets.** The Node snippet above is `tools/webhook-echo/verify.mjs` verbatim; the echo
service runs every delivery through it (`node --test verify.test.mjs` checks it against a test vector
shared with `tests/Unit/Integrations/WebhookSignerTest.php`, a rotation header and a 301-second-old
timestamp). The PHP snippet is copied into `WebhookSignerTest`. Live check on the dev stack
(2026-09-21): a `ping` and the `ticket.status_changed`, `ticket.resolved` and `ticket.comment_added`
deliveries of resolving ticket #44 were verified by webhook-echo (204); a tampered body and the same
delivery replayed after five minutes were answered 401.

**Not built (deviations from the plan above).** Static per-subscription `headers`; `WithoutOverlapping`
per subscription (ordering is not guaranteed anyway); the notification on auto-disable (V1-DP-06).
