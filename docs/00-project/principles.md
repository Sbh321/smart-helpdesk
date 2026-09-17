# Engineering principles

These principles resolve day-to-day decisions without a meeting. When a principle and a deadline conflict, the [MVP scope](../02-product/mvp-scope.md) is cut, not the principle — except that P8 (ship a complete system) outranks polish.

## P1 — Tenant isolation is not a feature; it is the floor

Every query, job, cache key, storage path, broadcast channel and log line carries tenant context. Isolation is enforced in one place per mechanism (global scope, middleware, path prefix) and proven by negative tests. See [03-architecture/tenancy.md](../03-architecture/tenancy.md).

## P2 — Permissions, not roles, guard behaviour

Code checks `tickets.assign`, never `isAdmin()`. Roles are editable bundles. See [03-architecture/security.md](../03-architecture/security.md).

## P3 — Modular monolith, understandable by one person

One Laravel application, module folders with explicit public surfaces, no microservices, no message bus abstraction over Laravel events. See [ADR-0004](../adr/0004-modular-monolith.md).

## P4 — Use the framework; do not abstract it away

Laravel's filesystem, mail, queue, cache, broadcasting and notification drivers *are* the provider abstraction. We add an interface only at a boundary we actually swap (object storage endpoint, realtime transport) and never wrap Eloquent in repositories. See [03-architecture/backend.md](../03-architecture/backend.md).

## P5 — Our algorithms are ours, explainable and deterministic

Priority, assignment, duplicate detection and SLA are implemented in plain PHP classes with pure functions, seeded randomness only in experiments, and an "explanation" output shown in the UI. No LLM/external AI in the MVP decision path.

## P6 — Smallest coherent dependency set

A dependency is added only with a recorded reason and a check that nothing already installed solves it. Two libraries never solve the same problem (one UI primitive layer, one form library, one router, one chart library).

## P7 — Configuration has layers

Code → environment (`.env`, infrastructure only) → platform settings (database) → tenant settings (database, JSONB) → feature flags. `.env` is never the store for business settings. See [03-architecture/configuration.md](../03-architecture/configuration.md).

## P8 — A smaller complete system beats a larger broken one

Every feature reaches the [Definition of Done](../10-quality/definition-of-done.md) or is cut to V1. Loading, empty and error states, authorisation, tenancy tests and docs are part of "done".

## P9 — Deterministic demos

The demonstration uses seeded data, local mail capture, self-hosted realtime and object storage. Nothing in the demo path calls a third-party service over the internet.

## P10 — Write it down where it will be read

Decisions go in ADRs, behaviour in `docs/`, plans in `roadmap/`, shortcuts in `MVP-SHORTCUT` comments. Documentation that contradicts an ADR is a bug.

## P11 — Verify versions and claims

Framework and package versions come from release pages, not memory ([01-research/versions.md](../01-research/versions.md)). Scale claims come from measurements ([10-quality/performance-testing.md](../10-quality/performance-testing.md)).

## P12 — Accessibility is part of the design system

Keyboard, focus, contrast, reduced motion and screen-reader labels are token and component concerns, not a final-week audit. See [06-design-system/accessibility.md](../06-design-system/accessibility.md).
