# ADR-0009 Realtime: broadcast events with polling first, Laravel Reverb when enabled

**Status:** Accepted (2026-09-17)

## Context

Agents need fresh lists, live ticket updates and a notification bell; sub-second delivery is not required. On-prem must work offline; the demo must not depend on a third party. Research: [01-research/realtime-options.md](../01-research/realtime-options.md).

## Decision

- Domain events implement `ShouldBroadcast` from day one on private channels `tenants.{tenant_id}.tickets` and `tenants.{tenant_id}.users.{user_id}`, payloads carrying IDs and summaries only. `BROADCAST_CONNECTION=log` in the MVP.
- The SPA polls with TanStack Query (`refetchInterval` 30 s lists, 10 s open ticket, `refetchOnWindowFocus`) and invalidates on its own mutations.
- **Should-have (milestone 3)**: `laravel/reverb` service in Compose, `@laravel/echo-react` hooks that invalidate queries; channel authorisation asserts channel tenant = session tenant. `ext-uv` baked into the PHP image. Polling remains the fallback when the socket is disconnected.

## Alternatives considered

Pusher (cannot run on-prem; two configurations), Soketi (abandoned 2024), SSE (thin Laravel support), FCM/web push (not for a desktop agent app; future).

## Consequences

Zero infrastructure until needed; a two-hour switch to Reverb later rather than a one-day retrofit; the demo degrades gracefully.

## Migration / future considerations

Presence channels for "agent viewing this ticket"; Reverb scaling with Redis pub/sub behind a load balancer; web push for the V1 customer portal.
