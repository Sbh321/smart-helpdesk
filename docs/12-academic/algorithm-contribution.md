# Algorithmic contribution (what is "ours")

Examiners will ask which parts were written by the student versus provided by frameworks. This page draws that line explicitly.

## Provided by frameworks/packages (not claimed as contribution)

Routing, ORM, validation, authentication primitives, OAuth2 server (Passport), queues, scheduling, mail transport and the mail server itself (Stalwart), IMAP client library, image resizing library, websocket server, UI primitives, table/virtualisation utilities, charts rendering, containerisation. The CACS452 guideline asks students to write their own modules "rather than relying on predefined APIs or Plugins except in some unavoidable circumstances"; each item in this list is such an unavoidable foundation, and none of them makes a helpdesk decision.

## Implemented by us (claimed contribution)

| Module | What we implement | Key techniques | Doc |
|---|---|---|---|
| Priority scoring | `BasicWeightedPriority` — weighted sum of scaled impact, urgency, customer tier and waiting time; thresholds to P1–P4; explanation of each part | Simple Additive Weighting, ageing | [priority-scoring](../05-algorithms/priority-scoring.md) |
| Agent assignment | `LeastLoadedAgent` — eligibility rules (availability, shift, skills, team, capacity), lowest open-tickets-to-capacity ratio, least-recently-assigned tie-break; fairness measurement | Least-connections load balancing, skill-based routing, round robin, Jain's index | [agent-assignment](../05-algorithms/agent-assignment.md) |
| Duplicate detection | `JaccardDuplicates` — own word extraction with stop words, Jaccard similarity of word sets, threshold and top five with shared words | Set similarity, precision/recall evaluation | [duplicate-detection](../05-algorithms/duplicate-detection.md) |
| SLA engine | `SimpleSlaTimer` and `WorkingHoursCalendar` — timer state machine with pause/resume accounting, recompute from start on priority change, once-only warning and breach checks, working-hours arithmetic | Finite state machine, time arithmetic over working windows | [sla-evaluation](../05-algorithms/sla-evaluation.md) |
| Ticket state machine | `TicketStatus` transition table with guards and side effects | FSM | [tickets](../04-domain/tickets.md) |
| Tenant isolation | Global scope + middleware + tenant-aware storage/cache/queue/channel naming + negative test suite | Defence in depth | [tenancy](../03-architecture/tenancy.md) |
| Business calendar arithmetic | `WorkingHoursCalendar` — adding and measuring durations across working windows, holidays and DST in a tenant time zone | Interval arithmetic over piecewise working time | [sla-evaluation](../05-algorithms/sla-evaluation.md) |
| Email threading and reply extraction | `ReplyParser` and inbound router — quote/signature stripping heuristics, plus-address and header-based thread matching | Rule-based text segmentation, message threading | [email](../04-domain/email.md) |
| History reconstruction and time analytics | change-capture trigger, backward/forward replay for as-of views, status-interval sweep, backlog-at-instant, daily snapshots with rebuild and drift verification | Temporal data, change data capture, interval algorithms | [history-and-time-analytics](../05-algorithms/history-and-time-analytics.md) |
| Media pipeline | upload verification (MIME sniffing, checksum), variant generation queue, quota accounting | Content validation, asynchronous processing | [media](../04-domain/media.md) |
| Webhook delivery | Signing (HMAC-SHA256 with timestamp), retry with exponential backoff and jitter, dead-lettering | Reliable delivery pattern | [webhooks](../07-api/webhooks.md) |

## Scope of the algorithms

The four decision algorithms are intentionally **minimal academic baselines** behind replaceable contracts ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)). They were chosen so that each can be explained on one page, verified by hand and evaluated quantitatively. The report presents them as baselines, uses the literature review to describe stronger techniques, and lists those as the planned replacements after the defence ([05-algorithms/future](../05-algorithms/future/README.md)).

## Level of rigour per algorithm

Each algorithm page provides: problem statement, inputs/outputs, formula, normalisation, pseudocode, complexity, worked example, edge cases, configuration, tests, experiment. The evaluation chapter aggregates results ([result-analysis-plan](result-analysis-plan.md)).

## Honest limitations to state in the report

- The weights, thresholds and eligibility rules are set by hand; nothing is learned from data.
- Duplicate detection compares plain word sets: no stemming, synonyms or weighting, so paraphrases are missed.
- Assignment decides one ticket at a time and ignores skill strength and priority.
- The SLA timer has one pause rule and only notifies on warning and breach.
- Each limitation maps to a named replacement in [05-algorithms/future](../05-algorithms/future/README.md).
