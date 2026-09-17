# Future algorithm designs

The MVP deliberately ships **minimal academic baselines** ([ADR-0023](../../adr/0023-minimal-replaceable-algorithms.md)). The pages here keep the richer designs from the research phase so they can guide the replacements after the project defence.

| Baseline (MVP) | Advanced candidate | Other replacement options |
|---|---|---|
| [Basic weighted priority](../priority-scoring.md) | [priority-scoring-advanced](priority-scoring-advanced.md) — eight factors, SLA proximity, hysteresis, AHP weights | rule engine; learned ranking from agent overrides; LLM triage as a reviewed suggestion |
| [Least-loaded eligible agent](../agent-assignment.md) | [agent-assignment-advanced](agent-assignment-advanced.md) — weighted skill levels, affinity, penalties, relaxation ladder | batch optimisation (Hungarian, OR-Tools); forecasting with shifts; learned routing |
| [Jaccard duplicate check](../duplicate-detection.md) | [duplicate-detection-advanced](duplicate-detection-advanced.md) — stemming, TF-IDF cosine, bigrams, structured fields | PostgreSQL FTS/BM25 ranking; pgvector embeddings (Laravel AI SDK); hybrid search with learned threshold |
| [Simple SLA timer](../sla-evaluation.md) | [sla-evaluation-advanced](sla-evaluation-advanced.md) — per-metric pause rules, recompute modes, escalation actions | multi-metric SLAs (next reply, periodic update), escalation chains with timeouts |
