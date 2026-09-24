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
| Not yet | the tenant foreign key and tenant scoping (M1-06), `platform_user` and `client` actors from their guards (M1-07, M3), the viewer API — all done by M3-03, see below |


## As built (M3-03)

**Viewer API.** `GET /v1/audit-logs` (`can:audit.view`: owner and admin; SPA session only, no API client
scope reaches it). A cursor feed, newest first on `(created_at, id)`, `per_page` 1–100 (default 25);
`page`, `sort` and `search` answer 422. Filters (comma lists, OR within a filter, AND across):
`filter[action]` (exact, or `user.*` for every action of a subject), `filter[actor_type]`
(`user, api_client, system, platform_user`), `filter[actor_id]`, `filter[subject_type]`,
`filter[subject_id]`, `filter[created_between]` (`YYYY-MM-DD,YYYY-MM-DD` in the workspace zone,
inclusive). The query names the workspace (`tenant_id = current`), and the nullable-tenant row-level
security policy hides platform entries as well, so another workspace's rows and platform rows (NULL
tenant) never appear. `AuditLogResource` over the typed view `AuditEntryView` (entry, `actor_name`,
`subject_name`): names come from one query per record type on the page (`Audit\Queries\AuditNames`,
users, API clients, roles, webhooks, SLA policies, tickets as `#number`, contacts, organisations, teams,
categories, skills, Agents), inside the workspace; a deleted record has no name.

**Actor types.** `user` (web or Sanctum guard), `api_client` (`api` guard), `platform_user` (any write on a
platform route: `auth:platform` makes the platform guard the default; before M3-03 these were recorded as
`user`), `system` (no authenticated actor, or passed explicitly by jobs such as the webhook auto-disable).

**Changes.** Three shapes, which the SPA reads alike (`features/audit/audit-format.ts`): `{field: {old, new}}`
for a state change, `{old: …, new: …}` or `{before: …, after: …}` beside context (a settings section, a
version), and free-form context (`{email, roles}`). Every audited action records something.

**Coverage.** `tests/Feature/Audit/AuditCoverageTest.php` performs each action below through its API (the
automatic webhook disable through the action the delivery job calls) and asserts one row with the actor
type, the subject and non-empty changes; platform actions also assert a NULL tenant.

| Action | Written by | Actor in the test | Changed in M3-03 |
|---|---|---|---|
| `user.invited` | `InviteUser` | user | — |
| `user.invitation_resent` | `ResendInvitation` | user | changes were empty; now `{email, roles}` |
| `user.role_changed` | `ChangeUserRoles` | user | — |
| `user.disabled`, `user.enabled` | `SetUserActive` | user | changes were empty; now `{is_active: {old, new}}` |
| `settings.updated` (any section, incl. priority, assignment and duplicate weights) | `Settings::update` | user | — |
| `role.created`, `role.permissions_changed` | `RoleController` | user | — |
| `role.deleted` | `RoleController` | user | changes were empty; now `{name, permissions}` |
| `api_client.created`, `api_client.revoked` | `CreateApiClient`, `RevokeApiClient` | user | — |
| `webhook.created`, `webhook.updated`, `webhook.disabled` | `SaveWebhookSubscription`, `SetWebhookActive` | user; api_client; system (auto-disable) | — |
| `sla_policy.updated` | `SlaPolicyController` | user | **was not written**; now `sla_policy.created`, `.updated` (`{field: {old, new}}` + `version`) and `.deleted` (a snapshot) |
| `tenant.created` | `ProvisionTenant` | platform_user | actor was recorded as `user` |
| `tenant.suspended`, `tenant.reactivated` | `ChangeTenantStatus` | platform_user | actor was `user`; changes now `{status: {old, new}}` (+ `reason`) |
| `ticket.priority_overridden` | `OverrideTicketPriority` | user | — |
| `attachment.downloaded` | — | — | not written (Could; volume concern), unchanged |

Also written, outside the list above: `user.invitation_accepted`, `user.logged_in`, `user.login_failed`,
`user.logged_out`, `user.password_reset` (security.md §Audit), `webhook.enabled`, `webhook.secret_rotated`,
`webhook.deleted`, `contact.created`, `contact.archived`, `contact.unarchived`, `agent.created`,
`agent.profile_changed`, `agent.shifts_changed`, `team.members_changed`, `tenant.updated`,
`platform_user.created`, `platform_user.logged_in`.

**Viewer.** Settings → Audit log in the SPA (`features/audit`), and audit entries in the History tab of a
record for viewers with `audit.view` ([frontend.md](../03-architecture/frontend.md) §M3-03). Platform
entries have no viewer in the SPA yet (`/platform-api/audit-logs` stays planned).

## Presentation and secrets (M4-11)

The audit log (Settings → Audit) and a record's History tab render recorded changes through the shared
formatter (`lib/format/record-values.ts`, M4-03): each entry folds to "N changes" and opens as field,
old → new, with names instead of ids and labels instead of stored values. No audited action records a
secret: API clients record their name and scopes, webhooks their name, URL and events, and a secret
rotation only that it happened (`webhook.secret_rotated`, no value). The one-time secrets of API clients
and webhooks live only in the dialog's local state; browser tests assert they are absent from the page,
the query cache and the mutation cache once the dialog closes, including after navigating away.
