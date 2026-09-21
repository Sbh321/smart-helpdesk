# Scheduler

One `scheduler` container runs `php artisan schedule:work` (exactly one replica). Every task is declared in its module's service provider and uses `onOneServer()` (Valkey lock, insurance if a second replica ever appears) and `withoutOverlapping()`. Time zone is UTC everywhere; tenant-facing times are rendered by the SPA.

## Schedule

| Command | Cadence | Queue/inline | Tenant strategy | Module |
|---|---|---|---|---|
| `sla:evaluate` | every minute | dispatches `EvaluateSlaTimers` to `sla` | one pass over all tenants' due timers (`FOR UPDATE SKIP LOCKED`), tenant context set per row batch | Sla |
| `tickets:reevaluate-priority` | hourly at :05 | batches of 500 on `default` | iterate active tenants with `tenancy()->run()`; open tickets only | Automation |
| `reports:snapshot-daily` | hourly at :20 | inline | per tenant, the local yesterday; replaces the day's rows (M2-13) | Reporting |
| `tickets:auto-close` | daily 03:10 | inline (small) | per tenant, `resolved_at <= now − tickets.auto_close_days` (the workspace setting), closed as actor `system`; built in M2-01 | Tickets |
| `media:cleanup` | hourly at :20 | `media` | pending uploads > 1 h; trashed items > 30 days (objects and variants deleted, quota released) | Media |
| `mail:fetch-inbound` | every minute | inline fetch, `mail` queue per message | IMAP fetch of the inbound mailbox; idempotent on `message_id`; `withoutOverlapping` | Mail |
| `agents:reconcile-workload` | daily 03:30 | `default` | recompute `active_ticket_count` from tickets; log discrepancies | Agents |
| `webhooks:retry-due` | every minute (`onOneServer`, `withoutOverlapping`) | dispatches `DeliverWebhook` on `webhooks` for `failed` deliveries with `next_attempt_at ≤ now`, and for `pending` ones whose job was lost (5 min overdue); built in M3-05 | reads the due `tenant_id`s, then `tenancy()->run()` per active tenant, tenant-tagged jobs | Integrations |
| `webhooks:prune` | daily 03:40 | inline, batches of 5 000 | deliveries older than `helpdesk.webhooks.retention_days` (30; `--days=`), all tenants; built in M3-05 | Integrations |
| `notifications:prune` | daily 03:50 | inline | read notifications older than 90 days | Notifications |
| `exports:prune` | daily 04:00 | inline | `report_exports` rows and their media items older than 7 days | Reporting |
| `sanctum:prune-expired --hours=24` | daily 04:10 | inline | central | Identity |
| `passport:purge` | daily 04:15 | inline | central | Integrations |
| `horizon:snapshot` | every 5 min | inline | central | — |
| `health:check` | every 5 min | inline | central | — |
| `backup:clean` | daily 01:00 | inline | central | — |
| `backup:run --only-db` | daily 01:30 | inline (long) | central | — |
| `backup:monitor` | daily 02:00 | inline | central | — |
| `storage:sync-backup` | daily 03:00 | `default` | all tenant prefixes | — |
| `queue:prune-failed --hours=168` | weekly Sunday 04:30 | inline | central | — |
| `telescope:prune --hours=48` | daily 04:40, `local` only | inline | central | — |
| `scheduler:heartbeat` | every minute | inline | writes `sh:{env}:scheduler:heartbeat` = now | Support |

Experiments (`experiment:run`) are never scheduled.

```php
// app/Modules/Sla/SlaServiceProvider.php
Schedule::command('sla:evaluate')->everyMinute()->onOneServer()->withoutOverlapping()->runInBackground();
Schedule::command('scheduler:heartbeat')->everyMinute()->onOneServer();
```

Laravel 13's `Schedule::group()` applies `onOneServer()->timezone('UTC')` to the whole set in one place.

## Tenant iteration

Two patterns, chosen per task:

1. **Row-oriented sweeps** (`sla:evaluate`, `webhooks:retry-due`): a single query across all tenants on the central connection with the RLS setting unset would return nothing, so these commands run as jobs that select `DISTINCT tenant_id` with due work first, then `tenancy()->run($tenant, fn() => ...)` per tenant. This keeps RLS in force and tags jobs per tenant.
2. **Tenant-oriented maintenance** (`tickets:auto-close`, `tickets:reevaluate-priority`): `Tenant::active()->each(fn ($t) => tenancy()->run($t, ...))`, equivalent to stancl's `tenants:run` but with explicit batching and per-tenant timing in the log.

## Heartbeat and health

`scheduler:heartbeat` writes a cache key every minute; `ScheduleCheck` in `/health` fails when it is older than two minutes; `SlaSweepCheck` fails when `sla:last_sweep_at` is older than three minutes. The SLA job writes that heartbeat when a sweep ends, also after failures or when it stopped at its 40 s time budget (a backlog continues in the next minute). The container healthcheck `schedule:heartbeat-check` (tiny command) reads the scheduler key so Docker restarts a hung scheduler.

## Running manually

```sh
docker compose exec app php artisan sla:evaluate
docker compose exec app php artisan tickets:reevaluate-priority --tenant=acme
docker compose exec app php artisan schedule:list
docker compose exec app php artisan schedule:run          # one tick, all due tasks
docker compose exec app php artisan schedule:test         # pick a task interactively
```

Maintenance windows use `schedule:pause` / `schedule:continue` (Laravel 13); `backup:run` and `sla:evaluate` are marked `evenWhenPaused()`.

## Testing with a frozen clock

Commands take time from the injectable `Clock`; tests bind `FrozenClock` and call the command directly (`artisan('sla:evaluate')`) after advancing the clock, asserting timer states and dispatched jobs (`Queue::fake()`). Schedule registration is covered by a test that asserts every expected command appears in `Schedule::events()` with `onOneServer` and `withoutOverlapping` set. In the demo, `demo:tick` shifts the same clock so SLA warnings and breaches happen on stage without waiting ([12-academic/demo-plan.md](../12-academic/demo-plan.md)).
