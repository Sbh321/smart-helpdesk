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

`user.invited`, `user.invitation_resent`, `user.role_changed`, `user.disabled`, `user.enabled`, `settings.updated`, `role.created`, `role.deleted`, `role.permissions_changed`, `api_client.created`, `api_client.revoked`, `webhook.created`, `webhook.updated`, `webhook.disabled`, `sla_policy.updated`, `settings.updated` (priority/assignment/duplicate weights), `tenant.created`, `tenant.suspended`, `tenant.reactivated`, `ticket.priority_overridden`, `attachment.downloaded` (Could-have; volume concern).

## Implementation choice

A small, purpose-built `AuditLogger` action plus one table is preferred over `spatie/laravel-activitylog`'s automatic model diffing, because (a) we want explicit, named actions with intent rather than column diffs, (b) tenant scoping and actor types (`user`, `api_client`, `system`) are first-class, and (c) it is ~150 lines. The decision and the package comparison are in [01-research/backend-ecosystem.md](../01-research/backend-ecosystem.md) §Audit logging.

## As built (M1-16)

| Part | Where |
|---|---|
| Table | `app/Modules/Audit/Database/Migrations/…_create_audit_logs_table.php`; `actor_type` has a CHECK constraint; indexes as in [indexing.md](../08-database/indexing.md) |
| Append-only | the migration revokes `UPDATE`, `DELETE` and `TRUNCATE` from the runtime role; the `AuditLog` model also throws on update and delete |
| Writing | `Audit::record($action, $subject, $changes, $tenantId, $actorType, $actorId)` calls the `RecordAuditLog` action |
| Defaults | actor from the current guard (`user`), otherwise `system`; tenant from tenancy when initialised; IP, user agent and `request_id` from the request; time from `Clock` |
| Subject type | snake-case class name, for example `ticket` or `role` |
| Not yet | the tenant foreign key and tenant scoping (M1-06), `platform_user` and `client` actors from their guards (M1-07, M3), the viewer API |

