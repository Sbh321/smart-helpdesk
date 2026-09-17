# Backend ecosystem research (Laravel / PHP)

Researched 2026-09-17 against laravel.com, php.net, Packagist and GitHub. Versions are the latest at that date; constraints to use are in [versions.md](versions.md). Every package below is classified as **Required for MVP**, **Useful for MVP**, **V1**, **Future** or **Rejected** with the reason, per [00-project/principles.md](../00-project/principles.md) P6.

## Framework and runtime

| Item | Finding | Decision |
|---|---|---|
| Laravel | **13.x** current (released 2026-03-17; bug fixes to Q3 2027, security to 2028-03). Laravel 12 is already past bug-fix support (2026-08-13). Supports PHP 8.3–8.5. Notable 13 features we use: `HasUuids` generating **UUID v7** by default, `Queue::route()` job→queue mapping, `#[Tries]/#[Backoff]` job attributes, `PreventRequestForgery` middleware. Sources: laravel.com/docs/13.x/releases | **Laravel 13**, `^13.0` |
| PHP | 8.5 (2025-11-20) is the only branch with active support beyond 2026; Pest 5, PHPUnit 13 and activitylog 5 require ≥ 8.4. Docker `php:8.5-fpm-alpine`, `php:8.5-cli-alpine`. | **PHP 8.5** |
| Installer | `laravel new` defaults to no starter kit (the React kit is Inertia + Fortify, which we do not want), SQLite, Pest, Boost. | `laravel new backend --database=pgsql --pest --no-node`, then `php artisan install:api` (Sanctum + `routes/api.php`). Boost kept as a dev dependency ([DX](../09-infrastructure/local-development.md)). |

## Authentication

| Option | Finding |
|---|---|
| Sanctum 4.3.3 | Documented path for a first-party SPA: session cookie + CSRF via `statefulApi()`; no tokens in the browser. Also issues personal access tokens with abilities. |
| Passport 13.8.0 | Full OAuth2 server: authorization code + PKCE, **client credentials**, device grant, personal tokens. Password and implicit grants are disabled by default and discouraged. Its SPA mechanism (`CreateFreshApiToken`) still relies on the session, so Passport-for-everything is the worst of both. |
| Fortify 1.39 | Headless login/registration/reset/2FA endpoints; now depends on `laravel/passkeys`. Its user lookup is by global email, which conflicts with per-tenant email uniqueness; customisation hooks exist but add coupling. |

**Decision ([ADR-0007](../adr/0007-authentication-and-oauth.md))**: Sanctum SPA cookie auth for the React app; Passport **client-credentials grant only** for tenant API clients in milestone 3, time-boxed to one day with Sanctum tokens (abilities named like OAuth scopes) as the fallback; Fortify rejected (hand-rolled login/invite/reset controllers are ~1 day and tenant-aware). Authorization-code OAuth for third-party apps is V1.

Classification: Sanctum **Required**; Passport **Useful (time-boxed)**; Fortify **Rejected**.

## Authorization

`spatie/laravel-permission` 8.3.0 (MIT, 4 open issues, active). Use `teams => true` with `team_foreign_key => 'tenant_id'` so roles and assignments are tenant-scoped in the shared schema; global roles (`tenant_id NULL`) give every tenant the default bundles. Sharp edges recorded for implementation: call `setPermissionsTeamId()` in tenancy middleware **before** `SubstituteBindings`; `unsetRelation('roles')` on tenant switch in jobs/tests; Redis cache store with a unique `CACHE_PREFIX`; wildcard permissions **off** (flat enumerated list is easier to render and audit). **Required.**

## First-party tooling

| Package | Version | Classification | Reason |
|---|---|---|---|
| Horizon | 5.49.0 | **Required** | Redis queue supervision, dashboard, failed-job retry, per-job tags (`tenant:{id}`). Not compatible with Redis Cluster (fine). Set `tries` explicitly; supervisor `timeout` < `retry_after`. |
| Telescope | 5.24.0 | **Useful (dev only)** | Records cross-tenant payloads centrally; never enabled in production. |
| Pulse | 1.8.1 | V1 | Overlaps Horizon for queues; extra production surface. |
| Reverb | 1.11.1 | **Useful (Should-have)** | First-party self-hosted websockets; see [realtime-options.md](realtime-options.md). |
| Pennant | 1.26.0 | V1 | No rollout to gate yet; tenant `settings` JSONB covers feature toggles. |
| Octane | 2.19.1 | Rejected for MVP | Stateful workers are a cross-tenant leak risk; PHP-FPM is enough. |
| Boost | 2.9.1 | **Useful (dev)** | Version-pinned Laravel docs and tools for coding agents. |
| Nightwatch | 1.30.1 | Future | Paid SaaS observability; name it, do not buy it. |
| AI SDK (`laravel/ai`) + `whereVectorSimilarTo()` (pgvector) | in 13 | Future/V1 | First-party route to semantic duplicate search in V1; MVP algorithms must be our own. |

## Audit logging

| Option | Finding |
|---|---|
| spatie/laravel-activitylog 5.1.1 | Manual `activity()->causedBy()->performedOn()->withProperties()->log()` API is good; needs a custom Activity model to add `tenant_id`; PHP ≥ 8.4. |
| owen-it/laravel-auditing 14.0.6 | Field-level diff auditing; heavier; more open issues. |
| Custom table | ~150 lines: `audit_logs` + `AuditLog::record()` action with actor polymorphism (`user`, `api_client`, `system`) and nullable `tenant_id` for platform actions. |

**Decision**: custom `audit_logs` for the security audit log and a purpose-built `ticket_events` table for the ticket timeline ([04-domain/audit.md](../04-domain/audit.md)). Reason: named intents rather than column diffs, explicit tenant/actor model, no dependency. activitylog **Rejected for MVP** (revisit if automatic model diffs become a requirement).

## API documentation

| Option | Version | Finding |
|---|---|---|
| dedoc/scramble | 0.13.43 | Zero-annotation OpenAPI 3.1 from routes, FormRequests and JsonResources; built-in UI; free core is MIT (PRO adds spatie/data and query-builder inference we do not use). Introspects the live DB, so generation runs after migrations. Pin `0.13.*`. 16 open issues, pushed daily. |
| knuckleswtf/scribe | 5.11.0 | Nicer static site with try-it console, but relies on response calls/annotations; 103 open issues. |
| darkaonline/l5-swagger | 11.1.0 | Fully manual attributes. |

**Decision ([ADR-0010](../adr/0010-api-documentation.md))**: Scramble, served at `/docs/api`, exported in CI to `openapi.json` for frontend type generation. **Required.**

## Operations packages

| Package | Version | Classification | Notes |
|---|---|---|---|
| spatie/laravel-health | 1.40.2 | **Required** | DB, Redis, queue, scheduler-heartbeat, disk checks; JSON endpoint used by Docker healthchecks and on-prem admins. |
| spatie/laravel-backup | 10.3.3 | **Useful (on-prem)** | `pg_dump` + files to any disk; image needs `postgresql-client`. |
| opcodesio/log-viewer | 3.24.2 | Useful (on-prem staff only) | Quietest package (last release 2026-06-11); logs contain cross-tenant data, gate to platform admins. |
| laravel/sentinel | 1.1.0 | transitive | Shared dependency of Horizon/Telescope/Pulse. |

## Quality tooling

Pint 1.32.1 (format), Larastan 3.12.1 (start level 5, ratchet to 6), Pest 5.2.0 on PHPUnit 13 (Pest requires PHP ≥ 8.4, confirming 8.5). rector-laravel 2.6.2 deferred to upgrade time. All **Required** except Rector (**V1**).

## Identifiers

Laravel 13's `HasUuids` generates UUID v7 (time-ordered, RFC 9562), stored in PostgreSQL native `uuid` (16 bytes) versus ULID's 26-byte text. Same index locality, smaller keys, universally parseable. **Decision: UUID v7 primary keys everywhere; tenant-scoped integer `number` on tickets for humans; never expose auto-increment IDs.** (`Str::orderedUuid()` is superseded; do not use.)

## Queues and scheduler in containers

Horizon as a dedicated service (`php artisan horizon`); scheduler as a dedicated single-replica service (`php artisan schedule:work`) with `onOneServer()` and `withoutOverlapping()` on every task. Scheduled tasks listed in [11-operations/scheduler.md](../11-operations/scheduler.md).

## Tenancy package compatibility notes

stancl/tenancy 3.10.1's documented Passport/Horizon/Telescope integrations all assume multi-database mode; in single-database mode Passport tables simply gain `tenant_id`, Horizon stays central (jobs tagged per tenant), Telescope stays dev-only. Full comparison in [multitenancy-options.md](multitenancy-options.md).

## Sources

laravel.com/docs/13.x (releases, installation, starter-kits, sanctum, passport, horizon, scheduling, eloquent#uuid-and-ulid-keys), php.net/supported-versions.php, hub.docker.com/_/php, packagist.org (laravel/sanctum, laravel/passport, spatie/laravel-permission, laravel/horizon, laravel/telescope, laravel/pulse, laravel/reverb, laravel/pennant, laravel/octane, spatie/laravel-activitylog, owen-it/laravel-auditing, dedoc/scramble, knuckleswtf/scribe, darkaonline/l5-swagger, spatie/laravel-health, spatie/laravel-backup, opcodesio/log-viewer, larastan/larastan, laravel/pint, pestphp/pest), spatie.be/docs/laravel-permission/v8 (teams, wildcard, cache), scramble.dedoc.co (usage, pro), tenancyforlaravel.com/docs/v3/integrations (passport, horizon, telescope), github.com/archtechx/tenancy.
