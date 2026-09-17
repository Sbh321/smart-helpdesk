# ADR-0022 Reporting module: database change capture, derived read models and a report catalogue

**Status:** Accepted (2026-09-17). Extends the analytics scope of the MVP from a dashboard to a detailed reporting module.

## Context

The owner requires "a very detailed reporting module that shows every aspect of our entities and how they existed in our system": not only current counts, but each entity's history (what a ticket, contact, organisation, agent or team looked like on a given date and how it changed) and time-based analysis (backlog on a date, time spent in each status, workload over time). Reports must stay tenant-isolated, must not slow down the ticket workflow, and must run on the same single PostgreSQL database.

## Decision

1. **Change capture in the database.** A generic PL/pgSQL trigger `record_entity_change()` on every reportable table (tickets, ticket_comments metadata, contacts, organizations, agent_profiles, teams, team_members, agent_skills, categories, skills, sla_policies, sla_targets, business_calendars, agent_shifts, media_items, users, tenant_settings) writes one row per insert/update/delete to `entity_changes`: entity type and id, version, operation, the changed attributes with old and new values, actor type/id and request id (read from `app.actor_type`, `app.actor_id`, `app.request_id` session settings that the application sets next to `app.current_tenant`), and the time. Triggers capture every write, including bulk and raw updates that model events would miss. Sensitive columns (password hashes, secrets, tokens, email bodies) are excluded by a per-table column list.
2. **Domain history stays.** `ticket_events`, `ticket_assignments`, `sla_events` and `audit_logs` keep their meanings ([ADR-0015](0015-audit-strategy.md)); reports read them where semantics matter ("assigned by the algorithm", "breached on escalation").
3. **Derived read models** (tenant-scoped, RLS-protected ordinary tables, maintained by queued jobs and rebuildable from history):
   - `report_ticket_intervals`: one row per continuous period in which a ticket had a given status, assignee, team and priority, with calendar and business duration.
   - `report_ticket_facts`: one row per ticket with lifecycle metrics (first response and resolution in wall-clock and business time, pending time, unassigned time, reopen and reassignment counts, SLA outcomes, channel, duplicate outcome, escalations).
   - `report_daily_snapshots`: per tenant, day (in the tenant calendar's time zone) and dimension combination, the end-of-day backlog and daily flows, used for long-range trends.
   Materialised views are not used because PostgreSQL does not apply row-level security to them.
4. **Report catalogue, not a query builder.** Each report is a PHP `ReportDefinition` declaring its allowed dimensions, measures, filters, default visualisation and drill-down target. A `ReportRunner` builds parameterised SQL only from those declarations, applies the tenant scope, caps result sizes and caches results for five minutes per tenant and parameter set. Users never write SQL.
5. **Entity 360 and point-in-time views.** Ticket, contact, organisation, agent, team and category pages have a history timeline (entity changes merged with domain events, emails and audit rows) and an "as of" date control that reconstructs the entity's attributes at that moment from `entity_changes` ([history-and-time-analytics.md](../05-algorithms/history-and-time-analytics.md)).
6. **Exports**: CSV (streamed) and XLSX (openspout 5.11, PHP 8.4/8.5) produced by queued jobs into the media library `Reports` folder with a signed download link; print-optimised report pages for PDF via the browser. Saved reports are a Should-have; scheduled email delivery is a Could-have.
7. **Module**: the `Reporting` module replaces the `Analytics` module named in [ADR-0004](0004-modular-monolith.md); its dependency rule is unchanged (it only reads and listens).
8. **Permissions**: `reports.view` (catalogue and entity pages within the user's other view permissions), `reports.export`, `reports.manage` (saved and scheduled reports), `history.view` (entity change logs and as-of views).

## Alternatives considered

- Eloquent model events or `spatie/laravel-activitylog` for change capture: miss query-builder bulk updates and raw SQL; rejected for completeness.
- Full event sourcing: rewrites the write model; far beyond the MVP.
- A separate analytics store (ClickHouse, DuckDB, a warehouse): a second isolation boundary and another service for on-prem; V1 if volume demands it.
- Materialised views: no RLS; rejected.
- A free-form report builder: attractive but unsafe and slow to build; the catalogue covers the requirement and can grow.

## Consequences

- Every write to a reportable table costs one extra insert; acceptable at MVP volumes and measured in the performance test.
- `entity_changes` grows fastest; it is indexed by `(tenant_id, entity_type, entity_id, version)` and partitioned by month in V1.
- Reports read derived tables, so the ticket workflow is never blocked by reporting; derived tables lag by seconds (queue) and can be rebuilt with `reports:rebuild`.
- Adds about six effort-days to the plan (capture, read models, runner and catalogue, report UI, entity 360 and exports).

## Migration / future considerations

Monthly partitioning of `entity_changes` and `report_ticket_intervals`; an external analytics store fed from `entity_changes`; custom report builder; scheduled deliveries; customer-satisfaction metrics once a portal exists.
