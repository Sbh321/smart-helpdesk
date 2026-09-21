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

## As built (M2-09)

- **Module.** `Notifications` listens to after-commit events and is imported by no domain module: `TicketAssigned`, `CommentAdded` (public reply or internal note), `SlaWarning`, `SlaBreached`, `PriorityChanged` (only a raise counts as an escalation), `NoEligibleAgent`. One listener class, `SendTicketNotifications`, holds the matrix above.
- **Recipients** (`Support\Recipients`): the assignee's user; for an SLA warning on an unassigned ticket, the members of its team; "managers" are active users holding `tickets.assign`. Only active users of the workspace, each once. Nobody is notified about their own action (the assigning manager, the author of a comment).
- **Classes.** One per row, on `TicketNotification`: `TicketAssignedToYou` and `SlaBreachNotice` also mail; the others are in-app and broadcast. Queued on `notifications`. The payload is ids plus a summary: `kind`, `ticket_id`, `ticket_number`, `ticket_title`, `summary`.
- **Storage.** `notifications` table with `tenant_id`, UUID v7 id, `notification_key` and a unique index on (tenant, user, key), written by `TenantDatabaseChannel` with `insertOrIgnore`, so a retried job or a repeated event stores nothing twice. Keys: `sla_warning:{timer}`, `sla_breached:{timer}`, `ticket_assigned:{assignment}`, `public_reply:{comment}`; an escalation is keyed by level and minute, an unassignable ticket by day. Not reportable (a delivery, not business data).
- **Broadcast.** Private channel `tenants.{tenant_id}.users.{user_id}`, event type = kind. The broadcast driver is `log` until realtime (M3).
- **Mail.** Laravel's mail message with the workspace name in subject and signature and a link to the ticket in the SPA. MVP-SHORTCUT: no logo (needs a public URL); V1: V1-NT-02.
- **API.** `GET /v1/notifications` (unread first, then newest; `filter[unread]=true`; `per_page` up to 100), `POST /v1/notifications/{id}/read` (idempotent), `POST /v1/notifications/read-all`. No permission: every query is limited to the signed-in user. `/v1/me` counts the unread ones.
- **SPA.** The bell polls the unread count every 30 s; see [components.md](../06-design-system/components.md) §NotificationBell.
- **Volume.** Every breach mails the managers once. The first sweep over the ~5 000 back-dated sample tickets on the dev stack produced 6 696 breach notifications; digests and backlog suppression are V1-NT-01.
- **Not built:** per-user preferences, export-ready notifications (with exports), realtime delivery (M3-16).
