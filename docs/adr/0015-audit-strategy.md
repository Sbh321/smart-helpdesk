# ADR-0015 Audit: purpose-built tables instead of an activity-log package

**Status:** Accepted (2026-09-17)

## Context

Two needs: a user-facing ticket timeline and a security audit log of administrative actions. spatie/laravel-activitylog and owen-it/laravel-auditing are healthy but tenancy-unaware and diff-oriented. Research: [01-research/backend-ecosystem.md](../01-research/backend-ecosystem.md) §Audit.

## Decision

- `ticket_events` (domain history: typed events with actor and old/new values) written by ticket, SLA and assignment actions.
- `audit_logs` (security audit: actor type/id, action, subject, changes JSONB, ip, user agent, request id, nullable `tenant_id`) written through a single `RecordAuditLog` action from Identity, Platform, Integrations, Sla and Tenancy settings code paths.
- Both are append-only from the application role (no UPDATE/DELETE grants on `audit_logs`).

## Alternatives considered

spatie/laravel-activitylog (manual API is good; automatic diffs invite over-logging; needs custom model for tenant), owen-it/laravel-auditing (field diffs, heavier).

## Consequences

About 150 lines of our own code; explicit named intents; no dependency; the timeline query is trivial.

## Migration / future considerations

Retention policies and export in V1; partitioning by month at scale; SIEM forwarding.
