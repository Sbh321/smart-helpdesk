# Observability

Lightweight by design ([ADR-0012](../adr/0012-deployment-architecture.md)): structured logs to stdout, a health endpoint, Horizon, and Telescope in development. No metrics/tracing stack in the MVP; the two decisions that make V1's OpenTelemetry adoption cheap (JSON logs with a request id, `OTEL_*` variables documented but unset) are taken now.

## Components

| Component | Purpose | Where |
|---|---|---|
| JSON logs (Monolog `JsonFormatter` on the `stderr` channel) | every request, job, command and exception with correlation fields | all backend containers; Caddy logs JSON too |
| Docker `json-file` driver, 10 MB × 3 per container | retention without filling disks | daemon.json + Compose |
| `GET /up` | framework boot only; container healthcheck | `app` |
| `GET /health` (spatie/laravel-health JSON + HTML) | dependency health for humans and uptime monitors | central domain, `HEALTH_TOKEN` or platform admin |
| Horizon dashboard | queue throughput, wait times, failed jobs, per-tenant tags | `/horizon`, platform admin |
| Telescope | requests, queries, jobs, mail, exceptions in development only | `/telescope`, `TELESCOPE_ENABLED=true` only when `APP_ENV=local` |
| `opcodesio/log-viewer` | browse container log files on-prem (files written by the `daily` channel in addition to stderr when `LOG_FILE_ENABLED=true`) | `/log-viewer`, platform admin |
| Health notifications | mail on failing checks | `HEALTH_NOTIFY_MAIL` |

## Log record

```json
{
  "message": "ticket.created",
  "level": "INFO",
  "datetime": "2026-10-03T09:14:22.117Z",
  "channel": "stderr",
  "context": {"ticket_id": "0199...", "number": 1042, "priority_level": "P2"},
  "extra": {
    "request_id": "01K6...",
    "tenant_id": "0199...",
    "user_id": "0199...",
    "actor_type": "user",
    "route": "tickets.store",
    "job": null,
    "duration_ms": 312,
    "app_version": "2026.10.1"
  }
}
```

Processors (`App\Support\Logging\ContextProcessor`) add `request_id` (from Caddy's `X-Request-Id` or generated), `tenant_id` (from tenancy), `user_id`/`actor_type` (from the guard), `job` (class + job id inside workers), `app_version`. The same `request_id` is passed to queued jobs (serialised on the `TenantAwareJob` trait) and sent to webhooks as `X-Helpdesk-Request-Id` so a delivery can be traced back ([logs.md](logs.md)).

## Health checks

```php
Health::checks([
    UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70)->failWhenUsedSpaceIsAbovePercentage(90),
    DatabaseCheck::new(),
    DatabaseConnectionCountCheck::new()->warnWhenMoreConnectionsThan(50)->failWhenMoreConnectionsThan(90),
    RedisCheck::new(),
    CacheCheck::new(),
    QueueCheck::new()->onQueue(['default', 'sla', 'webhooks'])->failWhenHealthJobTakesLongerThanMinutes(5),
    ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
    HorizonCheck::new(),
    StorageCheck::new(),            // custom: HEAD a known key on the s3 disk
    ReverbCheck::new(),             // custom, only when REALTIME_ENABLED
    BackupsCheck::new()->onDisk('backups')->youngestBackupShouldHaveBeenMadeBefore(now()->subDay()),
    DebugModeCheck::new(),
    EnvironmentCheck::new(),
    SlaSweepCheck::new(),           // custom: cache key sla:last_sweep_at within 3 minutes
]);
```

`health:check` runs every five minutes from the scheduler, stores results, and notifies on state change. The JSON endpoint returns 200 when all checks are ok/warning and 503 when any fails, which is what the uptime monitor and the customer's tooling key on. The Docker healthcheck deliberately uses `/up` so that a Valkey blip does not make Docker restart `app` in a loop.

## Metrics to watch (Horizon + health)

| Metric | Source | Warning | Why |
|---|---|---|---|
| Queue wait time per queue | Horizon `waits` config → notification | > 60 s (`sla`, `notifications`), > 300 s (`webhooks`, `exports`) | SLA breaches must be detected within a minute (FR-AUT-07) |
| Failed jobs | Horizon | any on `sla`; > 10/h elsewhere | webhooks failing for a tenant, mail outage |
| `sla:evaluate` duration and last run | scheduler heartbeat + log field `duration_ms` | > 30 s or older than 3 min | NFR-PERF-03 |
| p95 API latency for `tickets.index` | log `duration_ms` aggregated (`just logs app \| jq`) or Telescope in dev; k6 in E5 | > 300 ms | NFR-PERF-01 |
| DB connections | health | > 50 | FPM pool misconfiguration |
| Disk | health | > 70 % | log/backups growth |
| Backup age | health | > 24 h | RPO |

Horizon snapshots run every five minutes so the dashboard's throughput and runtime graphs are populated during the demo.

## Alerts

Health check failures and Horizon long-wait notifications go to `HEALTH_NOTIFY_MAIL` through the configured mailer; on-prem customers can point an uptime monitor at `/health` with the token header. No paging integration in the MVP.

## V1 path

OpenTelemetry PHP (core SDK stable; Laravel auto-instrumentation beta and documented for Laravel 9–12, so verify 13 first) with the `opentelemetry` PECL extension, an OTel collector/Grafana Alloy container, Loki for logs, Tempo for traces, Prometheus/Mimir for metrics, Grafana dashboards; Laravel Pulse for in-app performance views; Nightwatch (paid, Laravel first-party) as the managed alternative for the SaaS side. None of this changes application code because logs already carry `request_id` and go to stdout.
