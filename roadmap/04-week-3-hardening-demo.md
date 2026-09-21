# Milestone 3 — Hardening, developer platform and demo

**Milestone status:** `[~]` In progress — 6 of 21 tasks done (M3-17 inside M2-12, M3-01, M3-02, M3-04, M3-10, M3-20); M3-05 paused with a draft outside the repository.

Goal: productise (dashboard, API clients, webhooks, docs), harden (RLS, security review, performance), deploy (prod Compose, Ansible, OpenTofu), evaluate (experiments), and make the demo bullet-proof. Exit criteria: [00-mvp-definition.md](00-mvp-definition.md).

## Track plan

Work runs on four parallel tracks (see [12-schedule.md](12-schedule.md)): **A** backend and algorithms, **B** frontend, **C** infrastructure, mail/media plumbing, quality and academic artefacts, **D** algorithm cores and reporting. Each track can be the owner or a coding agent in its own git worktree; the owner reviews and merges. Tasks carry a `Track` line; dates are computed from [schedule.yaml](schedule.yaml) by `python3 roadmap/tools/schedule.py`, never written by hand.

| Track | Order |
|---|---|
| A | M3-04 → M3-05 → M3-19 → M3-07 → M3-10 → M3-03 → M3-16 → M3-17 |
| D | M3-02 → M3-21 → M3-11 → M3-12 |
| B | M3-01 → M3-20 |
| C | M3-06 → M3-18 → M3-14 → M3-09 → M3-08 → M3-13 → M3-15 |

---

## Epic H1 — Reporting, dashboard and audit

### `[x]` M3-02 Report catalogue and runner — L, critical
- **Done (2026-09-21):** `ReportDefinition` contract, `SqlReport` base, `ReportRunner` (periods, custom ranges up to two years, group, measures, filters, comparison suppressed below 5 records, 5-minute cache per workspace), `GET /v1/reports`, `/{report}`, `POST /{report}/run`, `GET /{report}/records` (drill-down), entity overview endpoints for tickets, contacts, organisations, agents, teams and categories. 28 catalogue reports: T01–T11, C01–C04, A01–A06, S01–S04, M01, G01, G02; C05, C06, A07 and T12 are the overview endpoints; E01, I01 and I02 arrive with M3-19, M3-05 and M3-04. Acceptance: every report runs with its defaults in under 1 s on the seeded test workspace, and each report's key totals equal an independent SQL or PHP computation; unknown dimension, measure, filter or period → 422; an agent without `contacts.view` neither lists nor runs contact reports. 130 reporting tests. Decisions and gaps: reporting.md §As built (M3-02).
- **Do:** `ReportDefinition` contract; `ReportRunner` (allow-listed dimensions/measures/filters → parameterised SQL over read models and history; period comparison; tenant scope; row caps; 5-minute cache); all catalogue entries in [reporting.md](../docs/04-domain/reporting.md) (tickets, contacts/organisations, agents/teams, SLA, email, media, integrations, administration); drill-down record queries; entity overview endpoints; permission filtering (`reports.view`, `history.view`, entity view permissions); endpoints in [conventions.md](../docs/07-api/conventions.md) §Notifications, reports, history, exports.
- **Acceptance:** every catalogue report runs on the demo seed within 1 s (p95) and its totals match independent SQL; unknown dimension → 422; an agent without `contacts.view` does not see contact reports.
- **Depends:** M2-13, M2-05, M2-08
- **Track:** D
- **Tests:** one feature test per report (shape and a known total); permission and isolation tests; ≈ 60 tests.
- **Docs:** reporting.md catalogue marked implemented; API docs.

### `[x]` M3-01 Dashboard — M
- **Done (2026-09-21):** `GET /v1/dashboard?period=` composed from catalogue reports through `ReportRunner` (8 KPI tiles with the previous period, 6 series, each naming its report; only what the caller may run). SPA: dashboard with KPI tiles, six Recharts charts themed from tokens (dark mode) with table alternatives, period in the URL, links into the full reports. Acceptance: every tile and series equals the corresponding catalogue run (feature tests); charts themed in dark mode; axe clean. Live on the dev stack. Screenshots for the report are left for M3-15.
- **Do:** `GET /v1/dashboard` composed from catalogue reports (KPIs and six series); UI: KPI tiles, six charts via shadcn `chart` (Recharts) with accessible table fallbacks, period selector in URL, links into the full reports.
- **Acceptance:** numbers equal the corresponding catalogue reports; charts themed in dark mode; axe clean.
- **Depends:** M3-02
- **Track:** B
- **Tests:** component test for a chart's table fallback; feature test for the endpoint.
- **Docs:** functional-requirements FR-ANL confirmed; dashboard screenshots for the report.

### `[x]` M3-20 Reports UI — XL, critical
- **Done (2026-09-21):** Reports section: catalogue grouped by group, report page with parameter bar (period presets, custom range, comparison, group-by, filters) in the URL, KPI tiles, chart per definition (bar, line/area, heatmap, histogram, table), shared DataTable, drill-down dialog, print stylesheet; export buttons wait for M3-09; saved reports (Should) skipped. Acceptance: reports render against MSW fixtures and live on the dev stack; drill-down lands on the records; axe clean on three reports (bar, area, heatmap). Found live: cached report results came back as incomplete objects (the cache refuses application classes); results are now cached as arrays, with a regression test on the redis store. Known gaps: the SPA hard-codes the "describes now" reports (rpt-t05, rpt-s04); stacked_area draws overlapping areas.
- **Do:** Reports section: catalogue grouped by entity; report page with parameter bar (period, comparison, group-by, filters) stored in the URL; KPI tiles with change against the previous period; chart per definition (bar, line, stacked area, heatmap, histogram, table); data table with the same numbers; drill-down to record lists; export buttons; print stylesheet; saved reports (Should); loading, empty and error states; accessible chart tables.
- **Acceptance:** every catalogue report renders on the demo seed; drill-down from a number lands on the matching records; export produces a file; axe clean on three representative reports.
- **Depends:** M3-02, M1-14
- **Track:** B
- **Tests:** browser tests for the parameter bar and drill-down; one E2E path in M3-12.
- **Docs:** screenshots; reporting.md page behaviour confirmed.

### `[ ]` M3-21 Entity 360 and history UI — L
- **Do:** overview pages for ticket, contact, organisation, agent, team and category (metrics, related records, trends); History tab with a unified timeline (entity changes, domain events, emails, audit) showing actor and old/new values; "as of" date-time picker with a diff against the current state.
- **Acceptance:** editing a contact's organisation twice shows both changes and each earlier state in the as-of view; the ticket lifecycle trace matches its intervals.
- **Depends:** M3-02
- **Track:** D
- **Tests:** browser tests for the as-of diff; permission test for `history.view`.
- **Docs:** reporting.md §Entity 360.

### `[ ]` M3-03 Audit log viewer and history polish — S
- **Do:** `GET /v1/audit-logs` (filters: actor, action, subject, range; cursor pagination), Settings → Audit page (Should: UI), ensure every action in [audit.md](../docs/04-domain/audit.md) writes; timeline polish on the ticket page (icons, grouping).
- **Acceptance:** each listed action produces a row with actor type and changes.
- **Depends:** M1-16
- **Track:** A
- **Tests:** table-driven audit assertions over actions.
- **Docs:** audit.md.

## Epic H2 — Developer platform

### `[x]` M3-04 API clients with OAuth2 client credentials — L, time-boxed 1 day
- **Done (2026-09-21):** Passport 13.8 for the token endpoint, JWTs and hashed secrets; our own `ApiClientGuard` (tenant from the stored token, revoked clients rejected on the next request), `Gate::before` from `Integrations\Domain\ScopeMap`, per-route opt-in for clients. `POST /oauth/token` (client_credentials only; unknown or ungranted scope → 400 `invalid_scope`), `GET/POST /v1/api-clients`, `POST /v1/api-clients/{client}/revoke` (secret shown once, audited), throttles 10 token requests and 120 calls a minute per client, `Idempotency-Key` on `POST /v1/tickets` and `/v1/contacts`, change-capture and audit actor `api_client`, Settings → API clients in the SPA. The Sanctum fallback was not needed (ADR-0007 Outcome). Acceptance, live on the dev stack with curl: token → `POST /v1/tickets` → 201 with `created_via=api` and actor `api_client`; token of workspace A on B → 401; revoked client → 401 at once; replayed Idempotency-Key → same response, one ticket. Tests: 47 backend, 4 browser plus an axe scan. Gap: ticket edits, transitions, comments and media stay SPA-only (MVP-SHORTCUT in Tickets routes).
- **Do:** Passport install without `passport:install` clients; `oauth_clients.tenant_id` + scopes; client-credentials grant; scope ↔ permission mapping middleware; multi-guard (`auth:sanctum,api`) on `/v1` with `EnsureTenantMembership` handling clients; `POST/GET/DELETE /v1/api-clients` (secret shown once, audit); per-client throttle; `Idempotency-Key` for `POST /tickets` (Should); UI: Settings → Developer → API clients; docs page snippet with curl.
- **Acceptance:** `POST /oauth/token` → bearer → `POST /v1/tickets` creates a ticket with `created_via=api` and `actor_type=api_client`; token from tenant A rejected on tenant B host; revoked client rejected.
- **Depends:** M1-09, M1-13
- **Track:** A
- **Tests:** feature tests for grant, scopes, tenant binding, revocation, idempotency.
- **Docs:** [authentication.md](../docs/07-api/authentication.md).
- **Fallback (when the one-day time box ends):** Sanctum tokens on an `ApiClient` model with the same abilities and endpoints; ADR-0007 updated with the outcome.

### `[ ]` M3-05 Webhooks — L, critical
- **Paused (2026-09-21):** a first backend draft (migration, models, signer, SSRF guard, retry schedule, job, listeners, API, tests) was interrupted by a network failure before it passed the gates. It was set aside so the tree could be committed green: the files are kept outside the repository in `../smart-helpdesk-m3-05-webhooks-draft.tgz` (new files at their paths, plus `shared-files/` with the versions of the shared files it had edited). Resume from that draft; nothing of it is in the repository.
- **Do:** migrations (webhook_subscriptions with encrypted secret, webhook_deliveries); subscription CRUD API + test-delivery endpoint; listeners mapping domain events to the catalogue in [integrations.md](../docs/04-domain/integrations.md); `DeliverWebhook` job on `webhooks` queue with signing, 10 s timeout, SSRF guard, retry schedule (1m, 5m, 30m, 2h, 12h), dead state, auto-disable after 20 consecutive failures; delivery log API with manual retry; `webhooks:prune` daily; `tools/webhook-echo` container (Node, verifies signature, prints); UI: Settings → Developer → Webhooks (form, events checklist, secret reveal once, delivery log table with retry, enable/disable).
- **Acceptance:** resolving a ticket produces a signed delivery verified by webhook-echo; a failing URL follows the retry schedule (tested with `FrozenClock` + `Queue::fake`); private IP URL rejected; replay outside 5 min rejected by the sample verifier.
- **Depends:** M3-04, M1-17
- **Track:** A
- **Tests:** signing/verification unit tests; job retry tests; SSRF validation tests; API tests; isolation.
- **Docs:** [webhooks.md](../docs/07-api/webhooks.md) verification snippets validated against webhook-echo.

### `[ ]` M3-06 API documentation polish — S
- **Do:** Scramble overrides for endpoints with poor inference; descriptions and examples on key resources; `/docs/api` gated to tenant users with `integrations.manage` and platform admins; `docs/07-api/CHANGELOG.md` v1.0; CI publishes `openapi.json` artifact.
- **Acceptance:** every `/v1` route appears with request/response schemas; no `string`-typed resources where a model exists.
- **Depends:** M1-13
- **Track:** C
- **Tests:** test that the export contains every route in `route:list`.
- **Docs:** documentation.md.

## Epic H2b — Email

### `[ ]` M3-18 Mail server and outbound identity — M
- **Do:** `mail` Compose service (Stalwart, pinned) with `mail-init.sh` (app submission account, inbound catch-all mailbox, DKIM key, printed DNS records); `MAIL_RELAY_*` smart-host settings; Laravel mailer on `mail:587`; `Mail` module outbound concerns: tenant sender display, `Message-ID`/`In-Reply-To`/`References`/`Reply-To: ticket+<uuid>@` headers on ticket mail, `List-Unsubscribe` for contact mail; Settings → Email (sender name, intake address, DNS records list); `just mail-send-test`; runbook for port-25-blocked hosts.
- **Acceptance:** a notification sent from the demo stack reaches Mailpit in dev and an external mailbox from a test VM with DKIM=pass, SPF=pass (or via relay); headers present.
- **Depends:** M1-04, M2-09
- **Track:** C
- **Tests:** header builder unit tests; mailable tests.
- **Docs:** [email.md](../docs/04-domain/email.md), [docker.md](../docs/09-infrastructure/docker.md) §Mail, runbooks.

### `[ ]` M3-19 Inbound email-to-ticket — L (Should)
- **Do:** `inbound_emails` table; `mail:fetch-inbound` (webklex/laravel-imap) every minute with `Processed`/`Failed` folders; `ReplyParser` (quotes, signatures) with fixture corpus; routing by plus-address, then `In-Reply-To`/`References`, then intake address; contact matching and creation; reopen within window; attachments to media (`Email` folder); auto-reply/bounce detection; `InboundEmailProcessed` event; unrouted/rejected notifications; inbound log view in Settings → Email; `just mail-inject`.
- **Acceptance:** replying to a notification email adds a public comment on the right ticket within 2 minutes; an email to the intake address creates a ticket with priority, assignment and duplicates run; a forged plus-address for another tenant's ticket is rejected; the same message processed twice creates one comment.
- **Depends:** M3-18, M2-07, M2-08
- **Track:** A
- **Tests:** parser fixtures (Gmail, Outlook, Apple Mail, auto-reply, bounce); routing table; idempotency; isolation.
- **Docs:** email.md; algorithm-contribution.md (ReplyParser is our own module).

## Epic H5 — Deployment

### `[ ]` M3-14 Production Compose, Ansible, optional OpenTofu — L
- **Do:** `infra/compose/prod.yaml` (no published DB/Valkey/RustFS ports, resource limits, log rotation, restart policies, profiles `storage`/`realtime`), prod Caddyfile variants (ACME on-demand with `ask`, customer cert, internal CA), single-tenant env example; Ansible roles and playbooks per [ansible.md](../docs/09-infrastructure/ansible.md) (site, deploy, backup, restore) with vault; OpenTofu module contracts plus provider folders (`none`, DigitalOcean, AWS, GCP, Hetzner) per [terraform.md](../docs/09-infrastructure/terraform.md), applying only the provider the owner has credentials for ([ADR-0017](../docs/adr/0017-cloud-agnostic-deployment.md)); deploy once to a cloud or VPS VM in multi-tenant mode and once to a local VM (on-prem simulation) in single-tenant mode, both via Ansible; backups (`spatie/laravel-backup` + pg_dump timer) and a restore rehearsal.
- **Acceptance:** `ansible-playbook site.yml` against any fresh Ubuntu/Debian VM yields a working HTTPS instance with seeded demo (and `tofu apply` does the same for the applied provider); restore from backup verified; runbooks updated with real commands.
- **Depends:** M1-04
- **Track:** C
- **Tests:** smoke script against the deployed host.
- **Docs:** production.md, terraform.md, ansible.md, backups.md, disaster-recovery.md updated with actuals.
- **Time box:** 1.5 days total; fallback: skip OpenTofu apply and deploy with Ansible to a manually created VM (cut item 8).


## Epic H3 — Hardening

### `[ ]` M3-07 Row-level security — M
- **Do:** migration generating `ENABLE/FORCE ROW LEVEL SECURITY` + policy for every tenant table from a list; grants for `helpdesk_app`; runtime connection uses the app role, migrations the owner role (`DB_USERNAME` vs `DB_MIGRATION_USERNAME`); `Platform` provisioning on the owner connection; tests: RLS enabled/forced on every tenant table, app role lacks `BYPASSRLS` and ownership, a raw `DB::table('tickets')->count()` without setting returns 0, with setting returns the tenant's rows; CI runs the suite as the app role.
- **Acceptance:** isolation suite v2 green; app boots and full E2E passes under the app role.
- **Depends:** M1-06, M1-10
- **Track:** A
- **Tests:** as listed.
- **Docs:** [08-database/tenancy.md](../docs/08-database/tenancy.md).

### `[ ]` M3-08 Security review — M
- **Do:** walk the checklist in [security-testing.md](../docs/10-quality/security-testing.md): headers via Caddy (curl verification), CSP with hashed boot script, cookie flags, rate limits, upload validation, SSRF, secrets scan, `composer audit`/`pnpm audit`, Telescope/Horizon/log-viewer gates, error detail leakage, dependency pins; fix findings; record residual risks.
- **Acceptance:** checklist committed with each item pass/fail/n.a.; no high findings open.
- **Depends:** M3-05, M3-07
- **Track:** C
- **Tests:** header test in E2E; route-protection test already.
- **Docs:** security.md residual risks section.

### `[ ]` M3-09 Report exports (CSV and XLSX) — M
- **Do:** `report_exports` table; `ExportReport` job streaming CSV (and XLSX with openspout) for any report or the filtered ticket list into the media library `Reports` folder; notification with signed link; `GET /v1/exports/{id}`; export buttons wired in the reports UI and the ticket list.
- **Depends:** M3-02, M2-08
- **Track:** C
- **Tests:** job test; isolation of download.
- **Docs:** API inventory.

## Epic H4 — Evaluation and performance

### `[x]` M3-10 Algorithm experiments — L
- **Done (2026-09-21):** datasets v1 (workload 8 agents × 500 tickets, 300 labelled duplicate pairs + 5 000-ticket haystack, 12 priority scenarios, 10 SLA timelines), `experiment:generate-*`, `experiment:run {e1,e2,e3,e4,e6,all} --strategy=baseline --seed=42` writing CSV, summary and `run.json`, random and round-robin comparison policies, `experiments/plots.py` (matplotlib 3.11.2, researched), `just reproduce` (~35 s). Results (seed 42): E1 baseline 0 capacity overflows (random 74, round robin 36), time-averaged Jain 0.97; E2 best threshold 0.30 → test F1 0.84, Recall@5 0.92; E3 12/12 scenarios; E4 10/10 timelines; E6 1 000/1 000 reconstructions, 300/300 tickets and 90/90 days agree, capture ≈ +0.2 ms per ticket update. Tables T1–T11 and plots 1–10 mapped in result-analysis-plan.md; T9 and plot 9 are filled by M3-11. Reproducibility test: two runs with seed 42 are byte-identical. Carried over: pasting the tables into `report/` (M3-15); E6 runs on 300 tickets, not 10 000 (stated in its run.json). Recommendation, not applied: lower the duplicate threshold default to 0.30 once real data confirms it.
- **Do:** dataset generators and scenario files per [evaluation-methodology.md](../docs/05-algorithms/evaluation-methodology.md); `experiment:run {e1..e6} --strategy=baseline --seed=42` writing CSV/JSON and `run.json`; comparison policies for E1 (random, round robin); `experiments/plots.py`; `experiments/README.md`; reproducibility test; result tables pasted into the report sources.
- **Acceptance:** `just reproduce` regenerates all tables T1–T11 and plots 1–10; results committed under `experiments/results/v1/`.
- **Depends:** M2-04, M2-05, M2-10, M2-03
- **Track:** A
- **Tests:** reproducibility test; generator tests.
- **Docs:** 05-algorithms §Experiment sections updated with actual numbers; 12-academic/result-analysis-plan.md status.

### `[ ]` M3-11 Performance sanity — M
- **Do:** seed 100 000 tickets in one tenant (bulk insert with `uuidv7()`), `EXPLAIN ANALYZE` the list query and duplicate candidate query (fix indexes if needed), SLA sweep with 10 000 timers timing, k6 script (50 VUs, list/detail/create/dashboard, thresholds), FPM/Horizon sizing notes; results table.
- **Acceptance:** NFR-PERF-01..05 met or deviations documented with cause.
- **Depends:** M1-17, M2-03
- **Track:** D
- **Tests:** —
- **Docs:** [performance-testing.md](../docs/10-quality/performance-testing.md) results.

## Epic H6 — Quality and demo

### `[ ]` M3-12 End-to-end tests and accessibility scans — M, critical
- **Do:** Playwright suite per [05-testing-plan.md](05-testing-plan.md): golden path, tenant isolation negative, theme persistence, webhook delivery via webhook-echo, API client flow via request context; `@axe-core/playwright` on login, dashboard, tickets list, ticket detail, settings; `e2e.yml` workflow against Compose.
- **Acceptance:** suite green locally and in CI; axe zero serious/critical violations.
- **Depends:** M2-10, M3-05, M3-20, M3-21
- **Track:** D
- **Tests:** this task.
- **Docs:** testing.md counts.

### `[ ]` M3-13 Demo dataset, reset/tick, rehearsal — M, critical
- **Do:** `DemoSeeder` per [11-demo-dataset.md](11-demo-dataset.md) (fixed seed, timestamps relative to now, near-breach timers, duplicate clusters, webhook to webhook-echo, OAuth client), `just demo-reset`, `just demo-tick` (shift timer timestamps by N minutes, run sweep), demo Compose profile; run the [demo script](../docs/12-academic/demo-plan.md) twice end to end offline; screenshots and a screen recording; fix rough edges found.
- **Acceptance:** reset < 60 s; every script step works offline; recording saved.
- **Depends:** M2-10, M3-05, M3-01, M3-02
- **Track:** C
- **Tests:** seed acceptance test (counts, states).
- **Docs:** demo-plan.md timings updated.

### `[ ]` M3-15 Documentation and report artefacts — M
- **Do:** final consistency pass over docs vs code (module map, endpoint inventory, settings keys, versions used), export Mermaid diagrams (`report/tools/export-figures.js`), take screenshots, fill Chapter 4 tables and Chapter 5 results in `report/university/`, rebuild both reports with `report/build.sh --final` once names are filled, collect test counts/coverage table, paste experiment tables, update ADR statuses/outcomes (e.g., Passport result, Table v9 result), write `docs/12-academic/implementation-notes.md` (module-by-module summary for Chapter 4), update README with deployment instructions.
- **Acceptance:** [06-documentation-plan.md](06-documentation-plan.md) checklist complete.
- **Depends:** M3-10, M3-12
- **Track:** C
- **Docs:** everything.

### `[ ]` M3-16 Reverb realtime — S (Should)
- **Do:** `reverb` service (profile `realtime`), `BROADCAST_CONNECTION=reverb`, channel authorisation routes, `@laravel/echo-react` hooks in ticket list/detail/bell invalidating queries, connection indicator, `features.realtime` flag.
- **Acceptance:** two browsers see a comment appear within 1 s; falls back to polling when the socket is down.
- **Depends:** M2-09
- **Track:** A
- **Tests:** channel authorisation tests (cross-tenant denied).
- **Docs:** realtime.md.

### `[x]` M3-17 Custom roles UI — S (Should, if not done in M2-12)
- See M2-12.
- **Done (2026-09-21) in M2-12:** Settings → Roles with the custom role editor.
- **Depends:** M1-09
- **Track:** A

## Final review

Run the MVP done checklist in [00-mvp-definition.md](00-mvp-definition.md); freeze `main`; tag `mvp-1.0`; move every `[-]` item into [09-v1-backlog.md](09-v1-backlog.md) §Cut during execution.
