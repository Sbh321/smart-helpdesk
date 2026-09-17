# ADR-0014 Cache and queues: Valkey with phpredis, Horizon

**Status:** Accepted (2026-09-17)

## Context

Redis 8 is tri-licensed (RSALv2/SSPLv1/AGPLv3); Valkey 9 is the BSD-3 Linux Foundation fork with identical wire protocol. stancl's Redis bootstrapper requires phpredis. Horizon supervises Redis queues and provides the dashboard.

## Decision

- **Valkey 9.x** (`valkey/valkey:9-alpine`) as the Redis-compatible store in all environments; service named `valkey`, env `REDIS_HOST=valkey`; any Redis-compatible service is acceptable in cloud.
- **phpredis** extension (`REDIS_CLIENT=phpredis`); predis is not installed.
- Uses: cache (tags per tenant), queues (`default`, `notifications`, `webhooks`, `sla`, `exports`), Horizon metadata, rate limiting, locks (`Cache::lock`), Reverb pub/sub when enabled. Sessions stay in the database in the MVP (simplifies inspection; tenant column).
- **Horizon 5** as the queue supervisor and dashboard on the central domain, jobs tagged `tenant:{id}`; `tries`, `backoff` and `timeout` set explicitly on every job; supervisor timeout below `retry_after`.
- Key namespace: `{APP_KEY_PREFIX}:{env}:` global prefix plus stancl's per-tenant cache tag/prefix; documented in [11-operations/queues.md](../11-operations/queues.md).

## Alternatives considered

Redis 8 (licence review friction for on-prem customers), database queue driver (no Horizon, slower), plain `queue:work` (no dashboard; fallback only).

## Consequences

One BSD-licensed dependency; queue observability for the demo; Horizon is not Redis-Cluster compatible (irrelevant at this scale).

## Migration / future considerations

Separate Valkey instances for cache vs queue vs Reverb when load demands; Redis Sentinel/Valkey cluster for HA.
