# Audit and history

Two different things are commonly called "audit". We keep them apart because they have different readers, retention and shapes.

| | Domain history | Security audit log |
|---|---|---|
| Question answered | "What happened to this ticket?" | "Who changed the rules, and when?" |
| Table | `ticket_events` (and `ticket_assignments`, `sla_events`) | `audit_logs` |
| Written by | Ticket module actions, SLA engine, assignment | Identity, Administration, Integrations, SLA settings, Tenancy modules |
| Shape | typed event with old/new values for the ticket | actor, action, subject type/id, changes JSONB, ip, user agent, request id |
| Reader | agents and contacts (timeline) | tenant admins, platform admins, examiner |
| Retention | life of the ticket | ≥ 1 year (V1: configurable) |
| Tenancy | `tenant_id` scoped | `tenant_id` nullable (platform-level actions have none) |

## Audited actions (MVP)

`user.invited`, `user.role_changed`, `user.disabled`, `role.created`, `role.permissions_changed`, `api_client.created`, `api_client.revoked`, `webhook.created`, `webhook.updated`, `webhook.disabled`, `sla_policy.updated`, `settings.updated` (priority/assignment/duplicate weights), `tenant.created`, `tenant.suspended`, `tenant.reactivated`, `ticket.priority_overridden`, `attachment.downloaded` (Could-have; volume concern).

## Implementation choice

A small, purpose-built `AuditLogger` action plus one table is preferred over `spatie/laravel-activitylog`'s automatic model diffing, because (a) we want explicit, named actions with intent rather than column diffs, (b) tenant scoping and actor types (`user`, `api_client`, `system`) are first-class, and (c) it is ~150 lines. The decision and the package comparison are in [01-research/backend-ecosystem.md](../01-research/backend-ecosystem.md) §Audit logging.
