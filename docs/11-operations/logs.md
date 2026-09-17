# Logs

All components write JSON lines to stdout/stderr; Docker keeps them with rotation; nothing is written to `storage/logs` in production unless the on-prem file channel is enabled for the log viewer.

## Format

| Field | Source | Always present |
|---|---|---|
| `datetime`, `level`, `channel`, `message` | Monolog | yes |
| `context` | call site (structured, not interpolated) | when given |
| `extra.request_id` | `X-Request-Id` from Caddy (generated per request by `request_id` placeholder) or generated in the app; carried into jobs | yes |
| `extra.tenant_id` | tenancy | when initialised |
| `extra.user_id`, `extra.actor_type` (`user`, `api_client`, `system`) | guard | when authenticated |
| `extra.route`, `extra.method`, `extra.status`, `extra.duration_ms` | request-completed log line | per request |
| `extra.job`, `extra.job_id`, `extra.attempt` | worker | inside jobs |
| `extra.command` | console | inside commands |
| `extra.app_version` | env | yes |

One `request.completed` line per request at `info` (method, route, status, duration), one `job.completed`/`job.failed` line per job, algorithm outcomes at `info` with ids only (`ticket.prioritised`, `ticket.assigned`, `duplicates.suggested`, `sla.transition`), and exceptions at `error` with stack traces. Caddy's access log adds `request_id` via the `{http.request.uuid}` placeholder forwarded as `X-Request-Id`.

## Levels

| Level | Use |
|---|---|
| `debug` | development only (`LOG_LEVEL=debug`), query timings from Telescope instead |
| `info` | lifecycle events listed above |
| `notice` | degraded paths taken (no eligible agent, polling fallback, storage retry) |
| `warning` | recoverable failures (webhook attempt failed, mail deferred) |
| `error` | exceptions, failed jobs, health failures |
| `critical` | tenant isolation assertion failures (`TenantMismatchException`), RLS setting missing on a tenant route |

Production runs at `info`.

## Never logged

Request/response bodies, ticket text, contact details beyond ids, passwords, session ids, bearer tokens, client secrets, webhook secrets or full signatures, presigned URLs, `.env` values, stack traces to clients. A `Redactor` processor masks fields named `password`, `token`, `secret`, `authorization`, `cookie` in context arrays as a backstop; a test feeds each into the logger and asserts masking.

## Request-id propagation

```text
Browser → Caddy (generates X-Request-Id if absent, logs it)
        → app (middleware stores it in the log context and the problem-details `request_id` field)
        → jobs (TenantAwareJob serialises it; worker restores it)
        → webhooks (X-Helpdesk-Request-Id header; stored on webhook_deliveries.request_id)
        → audit_logs.request_id
```

An error toast in the SPA shows the request id; searching logs, `webhook_deliveries` and `audit_logs` by that id reconstructs the whole chain.

## Access

| Environment | How to read |
|---|---|
| Dev | `just logs app`, `just logs horizon`; Telescope for structured browsing |
| Production (cloud) | `docker compose logs --since 1h app \| jq 'select(.extra.tenant_id=="…")'`; optional `opcodesio/log-viewer` at `/log-viewer` (platform admins only; logs contain cross-tenant data) when `LOG_FILE_ENABLED=true` writes a `daily` file channel alongside stderr |
| Production (on-prem) | same; customers' own aggregators can read the json-file driver output or attach a Loki/Alloy plugin without app changes |

## Retention

Docker: 10 MB × 3 per container (≈ a few days at MVP volume). File channel (if enabled): 14 daily files. Audit logs and webhook deliveries are database tables with their own retention ([04-domain/audit.md](../04-domain/audit.md), [scheduler.md](scheduler.md)). Logs are not part of backups.

## Development versus production

| | Dev | Prod |
|---|---|---|
| `LOG_LEVEL` | debug | info |
| Telescope | on | off |
| Query logging | Telescope | none |
| Pretty printing | `LOG_STDERR_FORMATTER=line` for readability if wanted | JSON |
| Caddy access log | on | on (JSON) |

## Correlation with other records

`webhook_deliveries` (request id, event id, attempt, response excerpt), `audit_logs` (request id, actor), `ticket_events` (actor, timestamp) and `failed_jobs` (payload contains the serialised request id) all share the request id, so a support question "why did tenant X get this email twice" is answered from the database plus a log grep.
