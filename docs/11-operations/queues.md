# Queues and background work

Valkey + Horizon ([ADR-0014](../adr/0014-cache-queue-infrastructure.md)). Rule NFR-PERF-06: nothing runs in an HTTP request except bounded synchronous work (duplicate preview, assignment on create); everything else is a job.

## Queues

| Queue | Jobs | Priority | Notes |
|---|---|---|---|
| `sla` | `EvaluateSlaTimers` (dispatched by `sla:evaluate`), `RunEscalationActions` | highest | must never wait behind mail |
| `notifications` | queued Notification classes (database, mail, broadcast) | high | fan-out per recipient |
| `broadcasts` | `BroadcastEvent` when `BROADCAST_CONNECTION=reverb` | high | tiny payloads |
| `webhooks` | `DeliverWebhook` (one per subscription × event), `RetryWebhookDeliveries` | normal | external HTTP, timeouts 10 s |
| `default` | `RecomputeTicketPriority` batches, `ReindexDuplicateFeatures`, `SyncAgentWorkload`, `ProvisionTenant` steps, cleanup jobs | normal | |
| `media` | `GenerateImageVariants`, `PurgeMediaItem` | normal | image processing is CPU-heavy; 1–2 processes |
| `mail` | `ProcessInboundEmail` | normal | one job per received message; retried 3 times, then `failed` state |
| `exports` | `ExportReport` (any report or the ticket list, CSV or XLSX), `RefreshTicketReport` bursts during `reports:rebuild` run on `reports` | low | long-running, memory-heavy |
| `reports` | `RefreshTicketReport`, `SnapshotTenantDay` | normal | one refresh per ticket event; idempotent |

Laravel 13's `Queue::route()` maps job classes to queues centrally in `app/Providers/QueueServiceProvider.php` so modules do not hard-code queue names.

## Horizon configuration (sketch)

```php
// config/horizon.php
'waits' => ['redis:sla' => 60, 'redis:notifications' => 60, 'redis:webhooks' => 300, 'redis:exports' => 600],
'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
'environments' => [
    'production' => [
        'critical' => ['connection' => 'redis', 'queue' => ['sla', 'broadcasts'], 'balance' => 'simple', 'processes' => 2, 'tries' => 3, 'timeout' => 60],
        'main'     => ['connection' => 'redis', 'queue' => ['notifications', 'webhooks', 'default'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 6, 'balanceMaxShift' => 1, 'balanceCooldown' => 3, 'tries' => 3, 'timeout' => 120],
        'exports'  => ['connection' => 'redis', 'queue' => ['exports'], 'balance' => 'false', 'processes' => 1, 'tries' => 2, 'timeout' => 600, 'memory' => 512],
    ],
    'local' => [ /* same supervisors, maxProcesses 3 */ ],
],
```

`config/queue.php` sets `retry_after => 660` for the redis connection, above the largest supervisor timeout (600) so a job can never run twice because of a timeout. Separate supervisors, not queue order under `auto`, give real priority. `horizon:terminate` is part of every deploy; `stop_grace_period: 60s` lets in-flight jobs finish.

## Job conventions

```php
final class DeliverWebhook implements ShouldQueue
{
    use Queueable, TenantAwareJob;                  // tenant id + request id serialised; tenancy re-initialised in the worker

    public int $tries = 5;                          // explicit: Horizon defaults to 1
    public array $backoff = [30, 120, 600, 3600];   // seconds; capped by RetryWebhookDeliveries for later attempts
    public int $timeout = 15;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $deliveryId) {}

    public function uniqueId(): string { return $this->deliveryId; }   // with ShouldBeUnique for sweeps/reindex jobs
    public function tags(): array { return ['tenant:'.$this->tenantId, 'webhook', 'delivery:'.$this->deliveryId]; }
}
```

- **`TenantAwareJob`** (in `app/Support`): stores `tenantId` and `requestId` on dispatch, initialises tenancy (`tenancy()->initialize`) before `handle()` via stancl's queue bootstrapper, ends it after, adds the `tenant:{id}` tag, and injects the request id into the log context. Jobs dispatched outside tenant context (platform) have `tenantId = null` and run centrally.
- **Idempotency**: every job that has side effects carries a natural key (`deliveryId`, `timerId + event`, `exportId`) and checks state before acting, so a retry after a crash never duplicates a notification or a webhook.
- **`ShouldBeUnique`** on sweep-style jobs (`EvaluateSlaTimers`, `SyncAgentWorkload`) so overlapping scheduler ticks cannot double-run.
- **Batches** for bulk work (`RecomputeTicketPriority` per 500 tickets) with `Bus::batch`.
- No job holds a database transaction across an external HTTP call.

## Failed jobs

Stored in `failed_jobs` (PostgreSQL) with tenant tag; Horizon shows and retries them; `queue:retry --queue=webhooks` bulk retry; `queue:prune-failed --hours=168` weekly. `sla` failures notify `HEALTH_NOTIFY_MAIL` immediately via Horizon's failed-job event.

## Key namespace

| Purpose | Key pattern |
|---|---|
| Global prefix | `REDIS_PREFIX=sh:{APP_ENV}:` on the `default` connection and `sh:{APP_ENV}:cache:` for cache |
| Tenant cache (stancl cache bootstrapper) | tag `tenant:{tenant_id}` on every cache call; `cache:clear --tags=tenant:{id}` |
| Tenant direct Redis (stancl redis bootstrapper) | `tenant:{tenant_id}:` prefix on `Redis::` calls |
| Rate limits | `rl:{tenant_id}:{user_id\|client_id}:{route_name}` (explicit keys; not affected by tenant prefix so platform limits stay global) |
| Locks | `lock:tenant:{tenant_id}:ticket:{id}:assign`, `lock:sla:sweep`, `lock:scheduler:{task}` (`onOneServer`) |
| Horizon | `horizon:` on its own connection name `horizon` |
| Scheduler heartbeat | `sh:{env}:scheduler:heartbeat`, `sh:{env}:sla:last_sweep_at` |
| Reverb pub/sub (when scaled) | separate connection `reverb` |

Sessions stay in PostgreSQL in the MVP; the cache store is `redis` with the tag-capable driver stancl needs.

## Tenant isolation in jobs and how it is tested

1. `TenantJobContextTest`: dispatch a job in tenant A's request; run the worker; assert `tenant('id') === A` inside `handle()` and `null` after.
2. `CrossTenantJobTest`: a job for tenant A loading a ticket id from tenant B gets `ModelNotFoundException` (scope) and, with RLS, zero rows.
3. `CentralJobTest`: platform provisioning job runs with no tenant and can read `tenants`.
4. `QueuedNotificationTenantTest`: the `notifications` row written by a queued notification carries the correct `tenant_id`.
5. Horizon tags asserted via `tags()` unit tests so the dashboard filter is trustworthy.

## Monitoring

Horizon dashboard (`/horizon`), `horizon:status` container healthcheck, `QueueCheck` in `/health` (dispatches a heartbeat job on `default`, `sla`, `webhooks`), long-wait notifications, `failed_jobs` count in the health JSON. Runbooks: [runbooks.md](runbooks.md#queue-backlog), [runbooks.md](runbooks.md#failed-jobs).
