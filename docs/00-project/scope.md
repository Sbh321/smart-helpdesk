# Scope

This document is the top-level boundary. The feature-level MoSCoW list lives in [02-product/mvp-scope.md](../02-product/mvp-scope.md); the V1 backlog in [roadmap/09-v1-backlog.md](../../roadmap/09-v1-backlog.md).

## In scope for the MVP

| Area | Included |
|---|---|
| Tenancy | Shared-database multi-tenancy with `tenant_id` scoping, tenant chosen at login and bound to the session or API client (SaaS) or single-tenant mode (on-prem), tenant-aware storage, cache, queues and realtime channels |
| Identity | Email/password login for tenant users, roles and granular permissions per tenant, platform super admin, OAuth2 client-credentials for integrations |
| Contacts | Contacts and organisations (the requesters), light metadata and tags |
| Tickets | Full lifecycle, comments (public reply / internal note), attachments, tags, categories, history, assignment |
| Teams and agents | Teams, skills, agent capacity and availability |
| Automation | Priority scoring, auto-assignment, duplicate suggestion, SLA timers with warning/breach/escalation |
| Notifications | In-app (database) and email through the bundled mail server or a relay; realtime push of ticket updates to the SPA |
| Media | Tenant media library (folders, tags, variants, quota) backing all attachments |
| Email channel | Outbound DKIM-signed mail; inbound email-to-ticket (Should-have) |
| Calendars | Tenant business calendars for SLA and ageing; agent shifts (Should-have) |
| Developer platform | `/v1` REST, OpenAPI docs, OAuth2 clients, signed webhooks for a small event set |
| Reporting | Change history for all main entities, report catalogue with drill-down, entity 360 and as-of views, dashboard, CSV/XLSX exports |
| Audit | Domain history on tickets plus a security audit log for sensitive admin actions |
| Infrastructure | Docker Compose for dev and production, Ansible for host configuration on any VM, optional OpenTofu provisioning for common clouds |
| Quality | Unit, feature, tenancy-isolation, permission and algorithm tests; Playwright for the golden path; CI |

## Explicitly out of scope for the MVP

Customer portal; live chat and social channels; knowledge base; custom fields and custom statuses; automation rule builder; online payment (plans, subscriptions paid by receipt and self sign-up are in scope since [ADR-0025](../adr/0025-plans-subscriptions-and-receipts.md)); white-label branding beyond logo and primary colour tokens; AI/LLM features; Meilisearch/Typesense/OpenSearch; Kubernetes; database-per-tenant placement (design hook only); mobile apps and push notifications; SSO/SAML; multi-language UI.

## Scope change rule

A feature enters the MVP only if it (a) is needed by the golden-path demo or an academic requirement, (b) has a roadmap task with acceptance criteria, and (c) displaces something of equal size, or the [risk register](../../roadmap/07-risk-register.md) shows slack. Otherwise it is written into the V1 backlog the same day and forgotten until then.
