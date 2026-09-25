# Terminology

Use these terms consistently in code, documentation, UI copy and the university report. Where a term has a tempting synonym, the synonym is listed as *not used*.

## Tenancy and identity

| Term | Meaning | Not used |
|---|---|---|
| **Platform** | The whole Smart Helpdesk installation (SaaS cloud or an on-premise install) | "system" for this meaning |
| **Tenant** | One customer organisation that has signed up to the platform; the unit of data isolation. Identified by a slug. | "account", "company" |
| **Workspace** | The user-facing name of a tenant: its slug in SPA URLs (`app.shp.subhambhandari.com.np/acme`) and on the login form. Use "tenant" in code and architecture, "workspace" in UI copy. | "organisation" for this meaning |
| **Platform domain** | `shp.subhambhandari.com.np` (dev `shp.localhost`); hosts `app`, `api`, `admin`, `monitor`, `docs`, `files`, `mail` sit under it ([ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md)) | |
| **Platform Super Admin** | A vendor-side user who manages tenants; never sees tenant business data in the MVP UI | "root", "god mode" |
| **User** | A person who can log in. Belongs to exactly one tenant in the MVP (except platform admins). | "member" |
| **Role** | A named bundle of permissions inside a tenant: Tenant Owner, Tenant Admin, Support Manager, Support Agent, Developer | |
| **Permission** | A granular capability string such as `tickets.assign` | "ability", "scope" (reserved for OAuth) |
| **Agent** | A user who can be assigned tickets; the agent profile holds skills, team memberships, capacity and availability | "staff", "operator" |
| **Contact** | A person who raises tickets (the requester). Contacts do not log in during the MVP. | "customer" (use only in prose), "requester" (use only as the role of a contact on a ticket) |
| **Organisation** | A company that contacts belong to; carries the customer tier used by priority and SLA | "account" |

## Ticket domain

| Term | Meaning |
|---|---|
| **Ticket** | A unit of support work with a lifecycle. Has a tenant-scoped human number (`#1042`) and a global UUID v7 ([ADR-0013](../adr/0013-identifiers.md)). |
| **Status** | One of `open`, `assigned`, `in_progress`, `pending`, `resolved`, `closed` (see [tickets.md](../04-domain/tickets.md)). `new` was merged into `open`; `reopened` is an event, not a status. |
| **Priority** | Discrete level `P1 critical`, `P2 high`, `P3 medium`, `P4 low`, derived from the **priority score** unless manually overridden |
| **Impact / Urgency** | The two ticket inputs to priority: how much of the business is affected (impact, 1–4) and how quickly harm grows (urgency, 1–4) |
| **Priority score** | The 0–100 number computed by the priority strategy; the level is a thresholded view of it |
| **Strategy** | A replaceable implementation of an algorithm contract (priority, assignment, duplicates, SLA); the MVP ships **academic baselines** to be replaced after the defence ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)) |
| **Category** | A tenant-defined classification (Billing, Technical, …) linked to skills for routing |
| **Skill** | A tenant-defined competence (e.g., `postgres`, `billing-refunds`) held by agents and required by categories |
| **Team** | A group of agents; tickets may be assigned to a team before an agent |
| **Assignment** | The act and record of giving a ticket to a team and/or an agent, with the reason (`auto`, `manual`, `reassign`, `escalation`) |
| **Comment** | A message on a ticket. **Public reply** is visible to the requester; **internal note** is agent-only. |
| **Media item** | Any file stored in object storage, owned by the tenant media library; an **attachment** is a media item linked to a ticket or comment |
| **Business calendar** | Tenant time zone, weekly working hours and holidays used by SLA timers and ageing; a policy may instead use 24×7 |
| **Shift** | An agent's scheduled working window; with shifts enforced, auto-assignment only considers agents on shift |
| **Inbound email** | A received email recorded with its routing outcome (comment, ticket, ignored, unrouted, rejected) |
| **Ticket history** | The domain event log of a ticket (status, priority, assignment changes) — distinct from the security audit log |
| **Duplicate suggestion** | A stored similarity result linking a new ticket to candidate existing tickets with a score |

## SLA

| Term | Meaning |
|---|---|
| **SLA policy** | Tenant-defined targets (first response, resolution) per priority and optionally per organisation tier |
| **SLA timer / instance** | The per-ticket application of a policy: `first_response_due_at`, `resolution_due_at`, state |
| **Warning** | Timer has passed the configurable warning fraction (default 75 %) of its target |
| **Breach** | Timer passed its target without the required event |
| **Pause** | Timer stopped while the ticket is `pending` (waiting on requester) |
| **Escalation** | The action taken on warning or breach (notify manager, raise priority) |

## Developer platform

| Term | Meaning |
|---|---|
| **API client** | An OAuth2 client (client-credentials) registered by a tenant for an integration |
| **Webhook subscription** | A tenant-registered URL plus event list and signing secret |
| **Webhook delivery** | One attempt record for one event to one subscription |
| **Plan** | What a workspace can be on: a free trial (days) or a paid plan (price per period of months) ([ADR-0025](../adr/0025-plans-subscriptions-and-receipts.md)) |
| **Subscription** | A workspace's plan and end date; its state (trialing, active, grace, expired) is derived, never stored |
| **Payment** | A workspace's payment for periods of a paid plan, with its receipt, reviewed by a Platform Super Admin |
| **Receipt** | The image or PDF that proves a payment; a Media item in the workspace's Billing folder |
| **Grace period** | The days after a subscription ends during which everything keeps working; then the workspace is read-only |
| **Sign-up** | A person creating a workspace themselves on the free trial, confirmed by an emailed link |

## Process

| Term | Meaning |
|---|---|
| **MVP** | The compressed, track-based deliverable defined in [02-product/mvp-scope.md](../02-product/mvp-scope.md). Never "prototype" or "demo app". |
| **V1** | The first post-MVP release; backlog in [roadmap/09-v1-backlog.md](../../roadmap/09-v1-backlog.md) |
| **ADR** | Architecture Decision Record in [adr/](../adr/README.md) |
| **Golden path** | The end-to-end demo flow in [12-academic/demo-plan.md](../12-academic/demo-plan.md) |
| **MVP-SHORTCUT** | A code comment marker `// MVP-SHORTCUT: <reason>; V1: <backlog item>` for deliberate simplifications |
