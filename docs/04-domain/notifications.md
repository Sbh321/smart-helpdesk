# Notifications domain

## Events that notify

| Event | In-app (database) | Email | Realtime (broadcast) | Recipient |
|---|---|---|---|---|
| Ticket assigned to me | ✔ | ✔ | ✔ | agent |
| New public reply on my ticket (from contact via API/agent) | ✔ | — | ✔ | assignee |
| Internal note on my ticket | ✔ | — | ✔ | assignee |
| SLA warning | ✔ | — | ✔ | assignee (or team members if unassigned) |
| SLA breach | ✔ | ✔ | ✔ | assignee + managers |
| Ticket escalated / priority raised | ✔ | — | ✔ | assignee, managers |
| Ticket unassignable (no eligible agent) | ✔ | — | ✔ | managers |
| Public reply / resolution on your ticket | — | ✔ | — | contact |
| Invitation / password set | — | ✔ | — | user |
| Export ready | ✔ | — | ✔ | requester of export |

One `Notification` class per row, implementing Laravel's `via()` with the channels above. The MVP does not have per-user preferences; the channel list is static per notification type (a `notification_preferences` JSONB on users is the V1 hook).

## Delivery mechanics

- All notifications are queued (`ShouldQueue`) on the `notifications` queue; tenant context is serialised with the job ([tenancy](../03-architecture/tenancy.md)).
- Email templates are Markdown mailables with the tenant's logo and name from tenant settings. Sending uses whatever Laravel mailer is configured (SMTP on-prem, provider API in cloud; Mailpit in dev).
- Realtime: private channel `tenants.{tenant_id}.users.{user_id}` for personal notifications and `tenants.{tenant_id}.tickets` for list refresh hints. Payloads carry only IDs and a summary; the SPA refetches via TanStack Query. See [03-architecture/realtime.md](../03-architecture/realtime.md).
- Dedup: a `notification_key` (`sla_warning:{timer_id}`) prevents double notifications on retried jobs.

## API

`GET /v1/notifications` (paginated, unread first), `POST /v1/notifications/{id}/read`, `POST /v1/notifications/read-all`. Unread count is included in `/v1/me`.
