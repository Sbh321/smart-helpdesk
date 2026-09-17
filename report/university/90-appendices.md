# Appendices

## Appendix A: Use case list

| ID | Use case | Primary actor |
|---|---|---|
| UC-01 | Manage tenants | Platform Super Admin |
| UC-02 | Manage users and roles | Tenant Admin |
| UC-03 | Manage teams, skills, categories and shifts | Tenant Admin |
| UC-04 | Manage SLA policies and business calendars | Tenant Admin |
| UC-05 | Manage priority, assignment and duplicate settings | Tenant Admin |
| UC-06 | Manage API clients and webhooks | Tenant Admin, Developer |
| UC-10 | Create ticket | Agent, external system, contact by email |
| UC-11 | View and filter tickets | Agent, Manager |
| UC-12 | Add public reply or internal note | Agent, contact by email |
| UC-13 | Attach file | Agent |
| UC-14 | Assign or reassign ticket | Manager, system |
| UC-15 | Change ticket status | Agent |
| UC-16 | Override priority | Manager |
| UC-17 | Review duplicate suggestions | Agent |
| UC-18 | View ticket history and SLA | Agent |
| UC-20 | Score priority | System |
| UC-21 | Auto-assign | System |
| UC-22 | Detect duplicates | System |
| UC-23 | Evaluate SLA and escalate | Scheduler |
| UC-30 | Manage contacts and organisations | Agent, Admin |
| UC-40 | View dashboard | Manager |
| UC-41 | Export report | Manager |
| UC-42 | Run detailed report | Manager, Admin |
| UC-43 | View entity 360 and history | Manager, Admin |
| UC-50 | Manage media library | Agent, Admin |
| UC-60 | Process inbound email | Scheduler |

## Appendix B: Source code excerpts

<<to be completed after implementation>>: `PriorityScorer`, `AgentAssigner`, `DuplicateDetector` with `PorterStemmer`, `SlaEngine` with `WorkingHoursCalendar`, `TicketStatus` transition table, the row-level security migration, and the webhook signing function.

## Appendix C: Screenshots

Screenshots in demonstration order, light theme, 1440 pixels wide: login with workspace; dashboard; ticket list with filters; new ticket with duplicate suggestions; ticket detail with explanations; SLA panel in warning; automation settings; business calendar editor; media library; email settings; API documentation; webhook delivery log; report catalogue; backlog report with drill-down; ticket history with as-of view; dark theme; tenant isolation (404).

## Appendix D: API excerpt

<<to be completed>>: excerpt of the OpenAPI document for ticket creation and the webhook payload format.

## Appendix E: Experiment datasets

Dataset versions, seeds, row counts and the command that regenerates every table and figure in Section 4.3.
