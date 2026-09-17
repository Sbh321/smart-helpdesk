# Realtime options

Researched 2026-09-17. Decision in [ADR-0009](../adr/0009-realtime-transport.md); design in [03-architecture/realtime.md](../03-architecture/realtime.md).

## Requirements

Agents need: fresh ticket lists, live updates on an open ticket (new comment, status, assignment), and a notification bell. Nothing requires sub-second delivery. On-premise installs must work without internet access. The demo must not depend on a third-party service.

## Options

| Option | Version | Finding | Verdict |
|---|---|---|---|
| Polling with TanStack Query (`refetchInterval`, `refetchOnWindowFocus`) | — | 10–30 s list refresh, 5–10 s on the open ticket; zero infrastructure; zero channel-auth surface. | **MVP default** |
| Laravel Reverb (self-hosted, Pusher protocol) | 1.11.1 (2026-08) | First-party; `install:broadcasting`; scales with Redis pub/sub; default `stream_select` loop caps near 1 000 connections, `ext-uv` lifts it (bake into image); proxy must route `/app` (WS) and `/apps` (HTTP). `allowed_origins` must be set. | **Should-have (milestone 3)**; the only on-prem-capable choice |
| Pusher Channels | pusher-js 8.6 | Free sandbox 100 connections / 200k msgs/day; protocol-compatible with Reverb, but cannot run on-prem or air-gapped → two configurations. | Rejected |
| Soketi | 1.6.1 (2024-03) | No development for ~2.5 years. | Rejected |
| Server-sent events | — | Laravel support is thin; polling is simpler. | Rejected |
| Firebase Cloud Messaging / Web Push | — | Push matters for users who closed the tab; for a desktop agent SPA that is email's job. Web Push (VAPID) is a standard without FCM but still unreachable air-gapped. | Future |
| Laravel Echo + `@laravel/echo-react` | 2.5.0 | `useEcho`, `useEchoPublic`, `useEchoPresence`, `useEchoModel` hooks with auto-cleanup; driver-agnostic. | Use when Reverb is enabled |

## Decision

1. Domain events (`TicketCreated`, `TicketUpdated`, `TicketAssigned`, `CommentAdded`, `SlaStateChanged`, `NotificationCreated`) implement `ShouldBroadcast` from day one on private channels `tenants.{tenant_id}.tickets` and `tenants.{tenant_id}.users.{user_id}`, with `BROADCAST_CONNECTION=log` in the MVP. Payloads carry IDs and a summary only; clients refetch through Query.
2. The SPA polls. Query invalidation on the client's own mutations keeps the UI immediate.
3. Milestone 3 Should-have: add the `reverb` Compose service, set two env vars, and replace polling with `useEcho` subscriptions that call `queryClient.invalidateQueries`. Channel authorisation uses the Sanctum session and asserts the channel's tenant equals the user's tenant.
4. The demo runs with Reverb if it is stable by the rehearsal; otherwise polling, which is indistinguishable at demo cadence.

## Sources

laravel.com/docs/13.x (reverb, broadcasting, notifications); github.com/laravel/reverb/releases; pusher.com/channels/pricing; github.com/soketi/soketi; github.com/laravel/echo/releases; npmjs.com/package/@laravel/echo-react; developer.mozilla.org Push_API.
