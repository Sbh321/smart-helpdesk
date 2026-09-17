# Goals and success criteria

## Product goals (MVP)

| # | Goal | Measurable success criterion |
|---|---|---|
| G1 | Complete ticket workflow | A ticket can be created, prioritised, assigned, discussed (public reply + internal note), attached to, resolved, reopened and closed through the UI and the API |
| G2 | Meaningful automation | Priority scoring, auto-assignment, duplicate suggestion and SLA timers run automatically on every ticket, with an explanation visible to agents |
| G3 | Strong tenant isolation | Automated tests prove Tenant A cannot read, write, list, search, download, or be notified about Tenant B data via UI, API, storage URLs, queues or realtime channels |
| G4 | Real permissions | Every API endpoint is gated by a named permission, not a role check; permission tests exist per module |
| G5 | Developer platform seed | Versioned REST API with OpenAPI docs at `/docs/api`, OAuth2 client credentials for third-party integrations, and signed webhooks for ticket and contact events |
| G6 | Runs anywhere | `docker compose up` on a workstation; the same images deploy with Ansible to any Linux VM — on-prem, self-managed VPS or any cloud provider — optionally provisioned by OpenTofu |
| G7 | Reliable demo | A deterministic seed produces multiple tenants, agents, teams, tickets, SLA breaches and duplicate examples; the demo needs no external network service |

## Academic goals

| # | Goal | Evidence produced |
|---|---|---|
| A1 | Self-implemented, explainable algorithms | Four algorithm modules with pseudocode, complexity analysis, unit tests and experiment scripts ([05-algorithms](../05-algorithms/)) |
| A2 | Result analysis | Reproducible experiments: fairness metrics for assignment, precision/recall/F1 for duplicates, sensitivity analysis for priority, scenario tables for SLA ([evaluation-methodology](../05-algorithms/evaluation-methodology.md)) |
| A3 | System analysis and design | Use-case, ER, class-level, sequence, state, activity and deployment diagrams in Mermaid, kept next to the architecture docs ([diagrams](../03-architecture/diagrams.md)) |
| A4 | Testing chapter | Test pyramid with counts and coverage per level ([testing](../10-quality/testing.md)) |
| A5 | Report mapping | Every engineering document is mapped to a report chapter ([report-mapping](../12-academic/report-mapping.md)) |

## Engineering goals

- **One-developer comprehensibility.** Any module can be explained on a whiteboard in five minutes.
- **Smallest coherent dependency set.** Each dependency has a recorded reason and no overlap ([backend](../01-research/backend-ecosystem.md), [frontend](../01-research/frontend-ecosystem.md)).
- **V1-ready seams, not V1 features.** Storage, mail, realtime, search and tenancy are behind Laravel's own driver abstractions so that swapping providers is configuration, not code.
- **Definition of Done applies to every feature** ([definition-of-done](../10-quality/definition-of-done.md)).

## Non-goals for the MVP

Customer self-service portal, email-to-ticket, live chat, knowledge base, custom fields, custom statuses, billing, white-label branding, AI features, external search engine, Kubernetes, high availability. See [scope.md](scope.md).
