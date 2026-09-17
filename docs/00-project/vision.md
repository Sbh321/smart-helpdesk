# Vision

**Smart Helpdesk** is a multi-tenant support-ticket management system that gives small and mid-sized support teams the parts of Zendesk-class tooling that actually decide day-to-day service quality: consistent prioritisation, fair and skill-aware assignment, early duplicate detection, and SLA visibility — without a per-seat enterprise price tag and without giving up control of their data.

Academic title: *Smart Helpdesk: A Multi-Tenant Support Ticket Management System with Automated Prioritization, Agent Assignment, Duplicate Detection, SLA Monitoring, Integrations, and Analytics.*

## Why it exists

| Problem observed in small support teams | What Smart Helpdesk does about it |
|---|---|
| Priority is set by whoever shouts loudest; nothing ages | A deterministic, configurable **priority score** computed from impact, urgency, customer tier and waiting time ([algorithm](../05-algorithms/priority-scoring.md)) |
| Managers assign tickets by hand; load piles on the "reliable" agent | A **least-loaded, skill-aware assignment rule** with round-robin tie-breaking that spreads work across qualified agents ([algorithm](../05-algorithms/agent-assignment.md)) |
| The same outage generates twenty tickets | **Duplicate detection** by word overlap (Jaccard similarity) at ticket creation, suggesting likely duplicates to the agent ([algorithm](../05-algorithms/duplicate-detection.md)) |
| SLA breaches are discovered in the monthly report | An **SLA engine** with warning and breach timers, pause/resume and business calendars ([algorithm](../05-algorithms/sla-evaluation.md)) |
| Data lives in a vendor's cloud, or in a tool nobody can integrate with | Same codebase runs **SaaS or on-premise**; a versioned REST API, OAuth2 clients and signed webhooks make it a platform rather than a silo |

## What it is not

Smart Helpdesk is not, and the MVP must not drift toward, a CRM, an omnichannel messaging suite, a knowledge base, a workforce-management tool, a BI platform, or a Kubernetes-native microservice estate. Those directions are recorded in [roadmap/09-v1-backlog.md](../../roadmap/09-v1-backlog.md) and [roadmap/10-future-architecture.md](../../roadmap/10-future-architecture.md) so that the architecture can anticipate them without implementing them.

## Two audiences, one system

1. **The university examiner** needs a complete, demonstrable system with system analysis, design diagrams, self-implemented algorithms, testing, and result analysis. See [12-academic](../12-academic/university-requirements.md).
2. **A future customer** needs a product whose foundations (tenant isolation, permissions, API, storage, deployment) do not have to be rewritten for V1.

The design rule that reconciles them: **build a smaller, complete, well-isolated system with meaningful algorithms and strong documentation** rather than a wide, half-working one. This rule is restated as the principles in [principles.md](principles.md) and enforced by the [MVP scope](../02-product/mvp-scope.md).

## Ten-second demo narrative

A tenant admin logs in → creates a contact → the contact's ticket arrives → the system scores its priority, flags a probable duplicate, assigns it to the least-loaded qualified agent and starts the SLA clock → the agent replies and resolves it → the dashboard shows SLA compliance and agent workload → a webhook fires to an external system. Every step is explainable in the UI ("why this priority?", "why this agent?").

The four algorithms are deliberately minimal academic baselines behind replaceable contracts; better approaches replace them after the project defence ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)).
