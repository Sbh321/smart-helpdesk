# Chapter 1: Introduction

## 1.1 Introduction

Every organisation that serves customers or employees receives requests: a login that fails, an invoice that is wrong, a server that is down. A helpdesk turns these requests into **tickets** that can be prioritised, assigned, tracked against a promised response time and closed with a record of what was done. Commercial platforms such as Zendesk, ServiceNow and Jira Service Management provide this capability to large enterprises, while small and medium teams commonly rely on shared mailboxes or open-source tools that store tickets well but leave most decisions to people.

Smart Helpdesk is a web-based support ticket management system built for such teams. One installation serves many independent organisations, called **tenants**, each with its own users, agents, customers, tickets and settings, and the same software can run as a hosted service or on an organisation's own server. Beyond storing tickets, the system makes four operational decisions automatically and explains each one: how urgent a ticket is, which agent should handle it, whether it repeats an existing ticket, and whether its service-level agreement (SLA) is at risk. It also records how every record changed over time and offers detailed reports on tickets, contacts, organisations, agents, teams and service levels, including what any record looked like on a past date. It delivers notifications by email and in the application, turns customer emails into tickets, keeps every file in a tenant media library, and exposes a documented REST API and webhooks so that other systems can integrate with it.

## 1.2 Problem Statement

Observation of how small support teams work, and a review of existing open-source helpdesks (Section 2.2), show the following problems:

1. **Inconsistent prioritisation.** Priority is chosen by whoever creates the ticket. Low-priority tickets can wait indefinitely, and a ticket's urgency does not grow as its deadline approaches.
2. **Unbalanced assignment.** Managers assign tickets manually or by simple rotation, so work accumulates on experienced agents while skill requirements and current workload are ignored.
3. **Duplicate tickets.** A single outage produces many tickets describing the same problem in different words; agents discover the duplication late and work on it in parallel.
4. **Late SLA awareness.** Response and resolution targets are checked after the fact in reports instead of warning agents before a breach; waiting for the customer and non-working hours are rarely handled correctly.
5. **Shallow reporting.** Existing tools report current counts, but managers cannot see how the backlog, an agent's workload or a customer's record looked on a past date, or how long tickets spent in each state.
6. **Isolation and deployment constraints.** A hosted helpdesk must guarantee that one customer organisation can never see another's data, while some organisations require the same software on their own infrastructure with their own mail and storage.

## 1.3 Objectives

The objectives of the project are:

1. To design and implement a multi-tenant helpdesk in which tenants are strictly isolated at the application, database and storage levels, and which can be hosted on any virtual machine or installed on-premise.
2. To implement a simple, configurable **priority scoring** algorithm based on weighted multi-criteria evaluation with an ageing term.
3. To implement an **agent assignment** algorithm that sends each ticket to the least-loaded qualified agent, and to measure its fairness.
4. To implement a **duplicate ticket detection** algorithm using the project's own word extraction and Jaccard similarity, and to measure its precision, recall and F1 score.
5. To implement an **SLA evaluation** state machine with warning, breach, pause, recomputation and working-hours calendars.
6. To provide the supporting product capabilities required for real use: ticket lifecycle with public replies and internal notes, media library, email notifications and email-to-ticket, dashboard, audit trail, REST API with OAuth2 client credentials, and signed webhooks.
7. To implement a **detailed reporting module** that captures every change to the main entities, reconstructs any record as it existed at a past moment, and analyses backlog, workload and time in each status over time.
8. To verify the system with unit, integration, system and tenant-isolation tests and to analyse the algorithms' results with reproducible experiments.

The four algorithms are intentionally minimal and replaceable, so that they can be understood completely, evaluated honestly and later substituted with stronger techniques.

## 1.4 Scope and Limitation

**Scope.** The system covers tenant provisioning, suspension and reactivation by a platform administrator through the platform API, with a separate sign-in for the platform console; authentication, invitations, roles and granular permissions per tenant; contacts and organisations with customer tiers; teams, skills, categories, agent capacity, availability and shifts; the complete ticket lifecycle with comments, attachments and history; the four algorithms with explanations visible to agents; SLA policies with business calendars and warning and breach notifications; in-app and email notifications through a bundled mail server; inbound email processing; a tenant media library; a management dashboard and a catalogue of detailed reports with drill-down, record history and point-in-time views; a versioned REST API with interactive documentation; OAuth2 client credentials and signed webhooks; light, dark and system themes with a comfortable and a compact density; and deployment with Docker Compose, OpenTofu and Ansible to any virtual machine, demonstrated on an Amazon Web Services instance.

**Limitations.**

- The priority and assignment weights are configured by administrators rather than learned from historical data.
- Duplicate detection is lexical: two tickets that describe the same problem with no shared vocabulary are not detected.
- The four algorithms are deliberately simple baselines: assignment ignores skill strength and decides one ticket at a time, duplicate detection does not handle word forms or synonyms, and SLA escalation only notifies. Each is built behind an interface so that it can be replaced after the project.
- A customer self-service portal, live chat and social media channels, a knowledge base, custom fields, billing and artificial-intelligence features are outside the scope and are listed as future work.
- Load and performance testing was not carried out within the project period; the system runs on a single small virtual machine and does not demonstrate high availability or horizontal scaling.
- The platform console offers sign-in and a read-only list of workspaces; creating, suspending and reactivating workspaces is done through the platform API.
- The agent application is designed for desktop and laptop screens (from 1024 pixels wide); layouts for phones are not provided.

## 1.5 Development Methodology

The project followed an **incremental development model** organised into four milestones, each ending with a working and tested system:

1. **Foundation:** repository, container environment, tenancy, authentication and permissions, design system, contacts and the ticket data model.
2. **Core product:** ticket workflow and user interface, SLA engine with calendars, priority scoring, agent assignment, duplicate detection, media library, notifications and reporting read models.
3. **Hardening and evaluation:** report catalogue and user interface, record history views, dashboard, API clients, webhooks, mail server and email-to-ticket, row-level security, deployment, experiments, end-to-end tests and demonstration preparation.
4. **User experience redesign:** an audit of every screen against usability findings, followed by the redesign of the navigation, ticket queue and ticket workspace, explanations of automated decisions, SLA presentation, dashboard, reports and charts, record history, settings and responsive behaviour.

Before implementation, a research and design phase compared candidate technologies and recorded each significant choice as an architecture decision record. Tasks were planned from an explicit dependency graph and executed in continuous development sessions on four parallel tracks (backend platform, frontend, infrastructure and quality, algorithms and reporting), with AI coding assistants used for implementation under the team's review; the pure algorithm modules were developed first because they do not depend on the database. The two team members developed features on separate branches that were reviewed and merged into a shared development branch, for example the platform console sign-in. Every task was accepted only against a written definition of done covering permissions, tenant isolation, validation, tests, accessibility and documentation.

Table 1.1: Development milestones

| Milestone | Main deliverables | Exit evidence |
|---|---|---|
| 1 Foundation | tenancy, authentication, RBAC, design system, contacts, ticket model, CI | two tenants isolated; tickets listed with server-side paging |
| 2 Core product | ticket workflow, SLA engine, priority, assignment, duplicates, media, notifications, reporting read models | automated decisions visible with explanations; history recorded |
| 3 Hardening | reports and record history, dashboard, API, webhooks, mail, RLS, deployment, experiments, E2E | results tables, green test suites, rehearsed demonstration |
| 4 UX redesign | navigation, ticket workspace, explanations, SLA, dashboard, reports, record history, settings, responsive layout | usability findings closed, accessibility scans green, demonstration screenshots regenerated |

## 1.6 Report Organization

Chapter 2 introduces the concepts the system is built on and reviews existing helpdesk systems and research on ticket prioritisation, routing, duplicate detection and SLA management. Chapter 3 presents the requirement and feasibility analysis, the object, dynamic and process models, the system design and the details of the four algorithms. Chapter 4 describes the tools and the implementation of each module, the unit and system test cases, and the analysis of experimental results. Chapter 5 concludes the report and recommends future work.
