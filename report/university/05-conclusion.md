# Chapter 5: Conclusion and Future Recommendations

## 5.1 Conclusion

This project set out to build a complete, multi-tenant helpdesk whose central decisions are made by the author's own explainable algorithms rather than by manual habit or opaque models. The delivered system provides the full ticket workflow for many isolated organisations, runs on any virtual machine or on an organisation's own server, and integrates with other systems through a documented API, OAuth2 clients, signed webhooks and email.

Against the objectives in Section 1.3: tenant isolation was enforced at three layers and verified by an automated suite (objective 1); the priority score, the assignment rule, the duplicate check and the SLA state machine were implemented as simple, tested and replaceable modules with explanations shown to agents (objectives 2–5); the reporting module recorded every change and reconstructed past states (objective 7); the supporting capabilities — media library, email in both directions, dashboard, audit, API and webhooks — were delivered (objective 6); and the testing and experiments in Chapter 4 quantified the behaviour of each algorithm (objective 8). <<summarise the measured results here: fairness improvement over round robin, duplicate F1 and recall, SLA scenario pass rate, performance margin>>

The main lessons were that explicit explanations make automatic decisions acceptable to users, that time handling (pauses, calendars, priority changes) is the hardest part of SLA management, and that tenant isolation must be tested systematically rather than assumed.

## 5.2 Future Recommendations

- **Customer portal and more channels.** A portal where contacts log in to follow their tickets, followed by live chat, WhatsApp, Telegram, Viber and SMS channels feeding the same ticket model.
- **Replacing the baseline algorithms.** Each algorithm sits behind an interface and its evaluation is repeatable, so better strategies can be introduced and compared on the same data: a priority score that also considers the SLA deadline, assignment that weighs skill strength and optimises batches, duplicate detection with weighted terms (TF-IDF or BM25) and stemming, and SLA rules with per-metric pauses and escalation chains.
- **Semantic duplicate detection and AI assistance.** Adding sentence embeddings stored in PostgreSQL (pgvector) as a further similarity channel, learning the channel weights from agents' confirmations, and offering ticket summaries, suggested replies and automatic categorisation as reviewable suggestions.
- **Learning weights from history.** Using resolved tickets to fit priority and assignment weights, and comparing the learned models with the configured ones.
- **Optimal batch assignment.** Applying the Hungarian method to rebalance unassigned tickets at shift boundaries, and adding shift rosters, leave management and on-call escalation chains.
- **Automation rules, custom fields and a knowledge base.** Letting tenants define their own rules and ticket fields, and linking articles to tickets to deflect repeated questions.
- **Scalability and availability.** Dedicated databases for large tenants, multiple application servers with shared realtime messaging, managed database services and monitoring with OpenTelemetry.
- **Richer analytics.** A user-defined report builder, scheduled report delivery, partitioned history tables and an external analytics store for very large tenants.
- **Custom domains and multi-workspace accounts.** Letting organisations use their own support domain and letting one person belong to several workspaces.
- **Commercial readiness.** Subscription billing, white-label branding with verified sending domains, single sign-on and multi-factor authentication.
