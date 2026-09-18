# Observability

Lightweight by design ([ADR-0012](../adr/0012-deployment-architecture.md)): structured logs to stdout, a health endpoint, Horizon, and Telescope in development. No metrics/tracing stack in the MVP; the two decisions that make V1's OpenTelemetry adoption cheap (JSON logs with a request id, `OTEL_*` variables documented but unset) are taken now.

## Components

| Component | Purpose | Where |
|---|---|---|
| JSON logs (Monolog `JsonFormatter` on the `stderr` channel) | every request, job, command and exception with correlation fields | all backend containers; Caddy logs JSON too |
| Docker `json-file` driver, 10 MB × 3 per container | retention without filling disks | daemon.json + Compose |
| `GET /up` | framework boot only; container healthcheck | `app` |
| `GET /v1/health` (spatie/laravel-health checks, own JSON) | dependency health for humans and uptime monitors | `api` host; `Authorization: Bearer <HEALTH_TOKEN>` or `X-Health-Token`; open without a token only when `APP_ENV=local`; platform admin access arrives with M1-07 |
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

Correlation fields come from Laravel's `Context`, which the framework merges into `extra` and serialises into every queued job: `request_id` (set by `AssignRequestId`), `tenant_id` (set by the tenancy bootstrapper, M1-06), and `job`, `job_id`, `attempt` or `command` (set by `App\Support\Logging\LogContext` in workers and commands). `App\Support\Logging\ContextProcessor` adds `app_version` (`APP_VERSION`) and, when a guard has already resolved a user, `user_id` and `actor_type`. The same `request_id` therefore reaches queued jobs without extra code and sent to webhooks as `X-Helpdesk-Request-Id` so a delivery can be traced back ([logs.md](logs.md)).

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

As built in M1-16, `App\Support\Health\HealthChecks` registers Database, Redis, Cache, Storage (`App\Modules\Media\Health\StorageCheck`: bucket exists, or a local disk is writable), Queue (`default`), Schedule, Horizon and UsedDiskSpace. DebugMode and Environment only run in production. The connection-count, backups, Reverb and SLA sweep checks are added by the tasks that build those parts.

The scheduler runs `health:schedule-check-heartbeat` and `health:queue-check-heartbeat` every minute, and `health:check` every five minutes. Results go to the Valkey cache (`HEALTH_CACHE_STORE`), so the app sees what the scheduler stored. Mail notifications are on only when `HEALTH_NOTIFY_MAIL` is set, and only for failures.

`GET /v1/health` runs the checks on every call. It answers with this shape:

```json
{ "data": { "status": "warning", "checked_at": "2026-09-17T18:07:58+00:00",
  "checks": [ { "name": "UsedDiskSpace", "label": "Used Disk Space", "status": "warning", "summary": "86%", "message": "The disk is almost full (86% used)." } ] } }
```

`status` is `ok`, `warning` or `failed`. The endpoint returns 200 for `ok` and `warning`, and 503 when any check failed or crashed, which is what the uptime monitor and the customer's tooling key on. The Docker healthcheck deliberately uses `/up` so that a Valkey blip does not make Docker restart `app` in a loop.

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
