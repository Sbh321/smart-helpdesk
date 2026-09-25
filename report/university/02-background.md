# Chapter 2: Background Study and Literature Review

## 2.1 Background Study

### 2.1.1 Tickets, priority and service levels

In service management a ticket is a unit of work with a lifecycle. Smart Helpdesk uses six statuses (open, assigned, in progress, pending, resolved and closed) because each one changes what the system does: an open ticket without an agent is a candidate for assignment, a pending ticket waits for the customer and pauses its SLA clock, and a resolved ticket can be reopened.

Priority is commonly derived from **impact** (how much of the organisation is affected) and **urgency** (how soon the effect becomes serious); Jira Service Management, for example, maps four impact and four urgency levels to five priorities with a lookup matrix [@atlassianPriority]. The ITIL 4 guidance notes that prioritisation also depends on the backlog and on target resolution times [@axelos2020], which motivated a score that also considers how long a ticket has waited.

A **service level agreement** defines targets such as first response time and resolution time. Products differ in the details, for example whether a timer pauses while the ticket waits for the customer and whether targets count calendar or business hours. Smart Helpdesk makes these behaviours explicit settings.

### 2.1.2 Weighted scoring and load balancing

**Simple Additive Weighting** scores an alternative as the weighted sum of its normalised criteria values [@saw2016]. Operating systems use **ageing** to stop low-priority work from waiting forever by raising its priority as it waits. For distributing work, load balancers and contact centres send each request to the least-loaded server or to the least-loaded agent who has the required skill [@gans2003]. The priority and assignment algorithms of this project are built on these ideas.

### 2.1.3 Text similarity

Information retrieval compares documents by the words they contain. Text is split into words, lower-cased and cleared of common stop words; the **Jaccard similarity** of two word sets is the number of shared words divided by the number of distinct words in both [@manning2008]. Richer methods weight words by how rare they are or use learned language models.

### 2.1.4 Multi-tenant software

A multi-tenant application serves several organisations from one deployment. Keeping all organisations in shared tables with a tenant identifier on every row is the simplest to operate but depends on every query being filtered correctly. PostgreSQL **row-level security** adds a filter enforced by the database itself, so a query that forgets the filter returns no rows instead of another organisation's data [@postgresRls]. The system uses both layers.

## 2.2 Literature Review

### 2.2.1 Existing helpdesk systems

Four open-source helpdesks were studied for the capabilities this project targets (Table 2.1).

Table 2.1: Comparison of existing open-source helpdesk systems

| System | Priority | Assignment | Duplicate detection | SLA |
|---|---|---|---|---|
| osTicket | manual levels per help topic | by department; manual claim | none | grace period after which a ticket is overdue |
| Zammad | three fixed levels | group rules; no workload awareness | manual merge | response and solution timers with calendars |
| GLPI | computed from an urgency and impact matrix | rules assign a technician or group | none | attached by rules |
| FreeScout | minimal | workflow rules | none | only through a paid module |

Only GLPI computes priority, and it uses a fixed matrix without waiting time. None of the systems assigns tickets by measured workload and skills, and none detects duplicate tickets automatically. Smart Helpdesk combines a transparent priority score, workload-aware assignment, duplicate detection and calendar-aware SLA timers in one system that explains each decision.

### 2.2.2 Related research

Recent work treats ticket priority and routing as classification problems solved with machine learning on historical tickets. These models need labelled history and cannot easily explain their output, so a transparent weighted score is a reasonable starting point for a new installation. For duplicate reports, Runeson et al. compared the words of new bug reports with existing ones and found about 30 % of duplicates among the top five suggestions [@runeson2007]; later work added weighted terms and learned models. For SLA timers, statecharts give a clear way to describe states that run alongside a ticket's workflow [@harel1987]. The algorithms of this project follow the simplest of these approaches and are built so that stronger methods can replace them later.
