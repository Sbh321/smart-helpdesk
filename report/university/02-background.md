# Chapter 2: Background Study and Literature Review

## 2.1 Background Study

### 2.1.1 Tickets, priority and service levels

In IT service management a ticket represents a unit of work with a lifecycle. Smart Helpdesk uses six statuses — open, assigned, in progress, pending, resolved and closed — because each one changes what the system does: an open ticket without an agent is a candidate for assignment, a pending ticket is waiting for the customer and pauses its SLA clock, and a resolved ticket can be reopened within a configurable window.

Priority is commonly derived from **impact** (how much of the organisation is affected) and **urgency** (how soon the effect becomes serious). Vendors implement this as a lookup matrix: Jira Service Management maps four impact levels and four urgency levels to five priorities [@atlassianPriority], ServiceNow uses data lookup rules [@servicenowPriority] and GLPI lets each entity edit its own matrix [@glpiMatrix]. The ITIL 4 incident management guidance, however, states that evaluating impact and urgency is not itself prioritisation; prioritisation depends on the whole backlog, resource availability and target resolution times [@axelos2020]. This distinction motivated a continuous score that also considers waiting time and the approaching deadline.

A **service level agreement** defines targets such as first response time and resolution time. Real products differ in important details: Zendesk measures targets in calendar or business hours and does not pause reply timers while a ticket is pending [@zendeskSla]; Jira Service Management models each SLA as a stopwatch with start, pause and stop conditions [@jiraSla]; ServiceNow can start a timer retroactively when priority changes [@servicenowSla]; Zammad measures first response from ticket creation and never resets it [@zammadSla]; osTicket uses a single grace period [@osticketSla]. Smart Helpdesk makes these behaviours explicit, configurable settings.

### 2.1.2 Multi-criteria decision making and scheduling

**Simple Additive Weighting (SAW)** scores an alternative as the weighted sum of its normalised criteria values [@saw2016]. It is compensatory: a weak value on one criterion can be offset by a strong value on another. The **Analytic Hierarchy Process** derives weights from pairwise comparisons and checks their consistency [@saaty1977]. Operating systems use **ageing** to prevent starvation in priority scheduling by raising the priority of waiting processes [@silberschatz], and **Earliest Deadline First** scheduling is optimal on a single processor when all tasks can meet their deadlines [@liu1973]. The baseline priority score in this project uses the simplest of these ideas, SAW with an ageing term; deadline-aware ordering is left for the replacement strategy.

### 2.1.3 Load balancing and skills-based routing

Network load balancers distribute requests by round robin, weighted round robin or least connections [@nginxLB]. Choosing the less loaded of two random servers reduces the maximum load dramatically compared with purely random placement [@mitzenmacher2001]. Contact centres route work by matching an agent's skills to the skill a request requires, and practical systems use greedy rules such as "least-loaded qualified agent" because optimal policies are intractable [@gans2003]. When a batch of tasks must be matched to workers optimally, the Hungarian method solves the assignment problem in polynomial time [@kuhn1955]. Fairness of a load distribution can be measured with **Jain's fairness index**, which equals one when all loads are equal [@jain1984].

### 2.1.4 Text similarity

Information retrieval represents documents as term vectors. Text is tokenised, case-folded, filtered for stop words and stemmed; the Porter algorithm removes suffixes in ordered steps conditioned on the measure of the remaining stem [@porter1980]. Terms are weighted by term frequency and inverse document frequency (TF-IDF), and two documents are compared by the cosine of the angle between their vectors [@manning2008]. Jaccard similarity compares sets of tokens, and n-gram overlap captures short phrases. BM25 is a probabilistic ranking function with term-frequency saturation and length normalisation [@robertson2009]. MinHash [@broder1997] and SimHash [@charikar2002] approximate similarity for very large collections. The baseline in this project uses only word sets and Jaccard similarity; TF-IDF, BM25 and embeddings are the planned improvements.

### 2.1.5 Multi-tenant software

A multi-tenant application serves several customer organisations from one deployment. Data can be isolated by a tenant identifier on shared tables, by a schema per tenant or by a database per tenant; the first option is simplest to operate but depends on every query being filtered correctly. PostgreSQL **row-level security** adds a database-enforced filter: a policy compares each row's tenant identifier with a per-session setting, so a query that forgets the filter returns no rows instead of another tenant's data [@postgresRls]. The system uses shared tables with application-level scoping through the Tenancy for Laravel package [@stancl] and row-level security as a second layer.

### 2.1.6 Temporal data and time analytics

Operational databases normally keep only the current state of each record. To answer questions about the past — the backlog at the end of a given day, or a customer's organisation before it changed — a system must keep either every version of each row (transaction-time or system-versioned tables, standardised in SQL:2011) or every change, from which versions can be rebuilt by replay. Recording changes inside the database with triggers captures every write path. Time-based measures such as "time spent in each status" are obtained by converting ordered state-change events into non-overlapping intervals, and the number of open tickets at an instant is the number of intervals that contain that instant. This project records changes with a trigger and derives intervals, per-ticket facts and daily snapshots from them.

### 2.1.7 Integration and messaging standards

The REST API reports errors as problem details [@rfc9457], authenticates external systems with the OAuth 2.0 client credentials grant [@rfc6749] and identifies records with time-ordered UUID version 7 values [@rfc9562]. Outgoing webhooks are signed with HMAC [@rfc2104] so that receivers can verify their origin. Outgoing email is signed with DKIM [@rfc6376] so that receiving servers can verify that the platform is allowed to send it.

## 2.2 Literature Review

### 2.2.1 Existing helpdesk systems

Six open-source helpdesks were studied for the four capabilities this project targets.

Table 2.1: Comparison of existing open-source helpdesk systems

| System | Priority | Assignment | Duplicate detection | SLA |
|---|---|---|---|---|
| osTicket [@osticket] | manual levels per help topic | by department or help topic; manual claim | none | grace period after which a ticket is overdue |
| Zammad [@zammad] | three fixed levels | group-based triggers; no load awareness | manual merge | first response, update and solution timers with calendars |
| GLPI [@glpi] | computed from an editable urgency × impact matrix | rules engine assigns technician or group | none | SLA and OLA attached by rules |
| FreeScout [@freescout] | minimal | workflow module rules | none | only through a paid module |
| UVdesk [@uvdesk] | priority field | workflow rules | none | separate SLA module |
| Znuny [@znuny] | priority field | queue-based | none | escalation times per queue or SLA |

Only GLPI computes priority, and it does so with a static matrix without ageing or deadline awareness. None of the systems assigns tickets by measured, skill-aware workload, and none detects duplicate tickets automatically. SLA support ranges from a single overdue threshold to a well-specified timer model. Smart Helpdesk's contribution is to combine a transparent priority score, fairness-measured assignment, retrieval-based duplicate detection and a calendar-aware SLA state machine in one explainable system, and to evaluate each quantitatively.

### 2.2.2 Ticket prioritisation research

Recent work treats priority as a classification task. Lê and Ait-Bachir compared embedding-based approaches and fine-tuned transformers on IT tickets; the best transformer reached an average F1 of about 0.785, and generic embeddings with clustering did not generalise [@le2025]. Ticket-BERT applies language models to labelling incident tickets [@zhang2023]. These models require labelled history and cannot explain their output to an agent who disagrees. A deterministic weighted score is therefore a reasonable starting point for a new installation with no history, and it is designed to be replaced by a richer or learned model later.

### 2.2.3 Ticket routing research

Shao et al. mined the sequence of groups that resolved past tickets to predict where a ticket should be transferred, without using ticket text [@shao2008]. SmartDispatch recommended a short list of three to five resolver groups using text classifiers in large IBM service engagements [@agarwal2012]. Mandal et al. combined an ensemble classifier with a configurable rule engine for dispatching helpdesk emails, keeping deterministic rules in the loop [@mandal2019]. These works focus on choosing the right group from history. The present project addresses the complementary problem of choosing an individual agent within the eligible set with a simple least-loaded rule, and shows the ranked candidates to the manager, as SmartDispatch does.

### 2.2.4 Duplicate report detection research

Duplicate bug reports are a well-studied problem. Runeson et al. applied tokenisation, stemming, stop-word removal and cosine similarity at Sony Ericsson, finding about 30 % of duplicates in the top five suggestions and 42 % in the top fifteen [@runeson2007]. Wang et al. added a second similarity channel based on execution traces [@wang2008]. Jalbert and Weimer framed detection as a filter for incoming reports [@jalbert2008]. Sun et al. trained a discriminative model [@sun2010] and then proposed REP, which extends BM25F with similarity of non-textual fields such as product and component and improved recall by 10–27 % [@sun2011]. The baseline duplicate detector in this project is simpler than all of these: it compares word sets with the Jaccard coefficient. The later methods — weighted term vectors, BM25F with structured fields (REP) and learned models — show the path for its replacement, and Runeson's recall figures set realistic expectations for a lexical approach.

### 2.2.5 SLA and escalation practice

Vendor documentation differs on whether timers pause while a ticket waits for the customer and on how a priority change affects a running timer [@zendeskSla; @jiraSla; @servicenowSla; @zammadSla]. PagerDuty models escalation as ordered levels with timeouts [@pagerdutyEsc]. Harel's statecharts provide a formal way to describe concurrent states such as a ticket's workflow and its SLA timer [@harel1987]. The baseline timer adopts the common core of these products — two timers, pause while waiting for the customer, warning before breach — and leaves configurable pause and recomputation rules to a later version.
