# Risk register

Reviewed every Friday (see §Weekly ritual) and whenever a trigger fires. Probability and impact: L/M/H. Owner is the solo developer for every risk; the "owner" column therefore names the *mitigating artefact* instead.

## Register

| ID | Risk | P | I | Trigger | Mitigating artefact |
|---|---|---|---|---|---|
| R01 | Scope explosion | H | H | any task not in the roadmap started | [mvp-scope.md](../docs/02-product/mvp-scope.md), cut list |
| R02 | Multi-tenancy complexity (stancl single-DB, bootstrappers, queues) | M | H | M1-06 not done by day 2 evening | [tenancy.md](../docs/03-architecture/tenancy.md), hand-rolled fallback |
| R03 | Passport complexity | M | M | M3-04 exceeds one day | Sanctum-token fallback (ADR-0007) |
| R04 | Conflicting frontend libraries | L | M | a `@radix-ui/*`, RHF or second chart lib appears in `package.json` | ADR-0002, dependency policy, Biome restricted imports |
| R05 | Weak tenant isolation (forgotten scope, raw query) | M | H | isolation suite red, or a raw query outside `Queries/` | reflection test, RLS, arch tests |
| R06 | Overbuilding infrastructure | M | M | more than 2 days on tofu/ansible total | ADR-0012 "< 200 lines", M3 time boxes |
| R07 | Realtime complexity | M | L | Reverb not working by M3 day 2 | polling default (ADR-0009) |
| R08 | Algorithms consume too much time | L | M | any baseline takes more than half a track-day | baselines are minimal by design (ADR-0023); advanced versions are post-defence work |
| R09 | Insufficient tests | M | H | coverage below weekly exit criteria | DoD, CI gates |
| R10 | Insufficient documentation | M | M | docs-check failing; pages stale > 1 week | documentation plan, DoD criterion 7 |
| R11 | External-service dependency during demo | L | H | demo script touches the internet | Mailpit, RustFS, webhook-echo, Reverb local; offline rehearsal |
| R12 | University deadline | M | H | M3 buffer consumed before day 3 | cut list, report artefacts generated continuously |
| R13 | RustFS 1.0 immaturity | M | M | presigned PUT/CORS bug in M1-04 | Garage alternative tested in M1; BYO S3 |
| R14 | TanStack Table v9 documentation gap | M | M | DataTable not working by end of M1 day 5 | fallback to `@tanstack/react-table@8` |
| R15 | TypeScript 7 tooling gaps | L | L | a needed tool cannot parse TS 7 | pin TS 6.x line; Biome unaffected |
| R16 | Scramble inference gaps | M | L | > 10 endpoints need manual overrides | per-route PHPDoc overrides; conventional resources |
| R17 | Solo developer illness/burnout | M | H | two consecutive days lost | buffer days, cut list, daily stopping rule (no work past 10 h) |
| R18 | Laravel/PHP/package version drift before start | L | L | `versions.md` re-check finds a breaking release | pin to researched versions; upgrade after MVP |
| R19 | Data model rework mid-milestone 2 | M | H | a migration touching > 3 tables after M2 day 2 | entities page reviewed M1 day 5; secondary-table `tenant_id` from day 1 |
| R20 | Demo laptop failure | L | H | — | second machine with images pulled; screen recording; screenshots |
| R21 | Host layout friction (shared `.shp` cookie, CORS with credentials, `*.localhost` TLS) | M | M | login from `app` to `api` fails in M1-06/08 | `tls internal`; `TENANCY_SINGLE_TENANT` mode as dev fallback |
| R22 | Scheduler/queue tenant context bug (stale tenant in worker) | M | H | isolation item 6 red | `QueueTenancyBootstrapper`, `TenantAwareJob`, tests |

## Risk details

### R01 Scope explosion
**Description:** features outside [mvp-scope.md](../docs/02-product/mvp-scope.md) creep in (portal, custom fields, rule builder), or Could-haves start before Musts are green. The sibling project in this workspace is the cautionary example. **Probability:** H. **Impact:** H. **Mitigation:** every task has an ID in the roadmap; a new idea goes to [09-v1-backlog.md](09-v1-backlog.md) the same day; Could-haves need all Musts of the week green. **Fallback:** cut list (below). **Trigger:** a branch with no task ID; roadmap task count grows.

### R02 Multi-tenancy complexity
**Description:** stancl single-DB mode, disabled database bootstrapper, queue re-initialisation and permission team ids interact in non-obvious ways. **P:** M. **I:** H. **Mitigation:** M1 day 2 is reserved for tenancy only; isolation tests written first; central vs tenant route split kept minimal. **Fallback:** replace stancl with the hand-rolled trait + middleware (~200 lines, documented in [multitenancy-options.md](../docs/01-research/multitenancy-options.md)) while keeping the same test suite. **Trigger:** M1-06 not green by day 2 evening.

### R03 Passport complexity
**Description:** keys, five tables, tenant binding of clients and multi-guard routes exceed the one-day box. **P:** M. **I:** M. **Mitigation:** client-credentials only; shared keys; clients table extended with `tenant_id` by one migration. **Fallback:** Sanctum tokens on an `ApiClient` model with identical scope names (ADR-0007). **Trigger:** M3-04 not green by end of M3 day 1.

### R04 Conflicting frontend libraries
**Description:** copying snippets brings Radix, React Hook Form, a second chart library or Zustand into the bundle. **P:** L. **I:** M. **Mitigation:** ADR-0002/0003, Biome `noRestrictedImports`, dependency policy. **Fallback:** remove and rewrite the component the same day. **Trigger:** dependency review flags a new UI/state package.

### R05 Weak tenant isolation
**Description:** a secondary model without a trait, a `DB::table()` in analytics, or a broadcast channel without a tenant check leaks data. **P:** M. **I:** H (project-defining). **Mitigation:** reflection test from M1, RLS in M3, arch tests banning raw queries outside `Queries/`, channel tests. **Fallback:** RLS moved forward to M2 if any leak is found. **Trigger:** any isolation test red, or a leak found manually.

### R06 Overbuilding infrastructure
**Description:** OpenTofu/Ansible/Compose polishing consumes M3. **P:** M. **I:** M. **Mitigation:** hard boxes: tofu 0.5 day, Ansible 1 day, deploy rehearsal 0.5 day; no managed services, no HA. **Fallback:** ship Compose + Ansible only; tofu reduced to compute + DNS. **Trigger:** infra tasks exceed 2 days total.

### R07 Realtime complexity
**Description:** Reverb, channel auth and Caddy websocket proxying eat time. **P:** M. **I:** L. **Mitigation:** polling is the default; Reverb is a Should-have flag. **Fallback:** polling in the demo. **Trigger:** Reverb not working by M3 day 2.

### R08 Algorithm implementation time
**Description:** the algorithm modules and their experiments take longer than planned. **P:** L. **I:** M. **Mitigation:** minimal baselines with worked examples written before coding ([ADR-0023](../docs/adr/0023-minimal-replaceable-algorithms.md)); experiments share one harness. **Fallback:** report the scenario tables and drop the weight-change sensitivity. **Trigger:** any baseline task exceeds half a track-day.

### R09 Insufficient tests
**Description:** tests deferred "until the UI works". **P:** M. **I:** H. **Mitigation:** DoD; tests in the same task; CI required checks from M1 day 5. **Fallback:** M3 day 4 reserved for test debt only if coverage is under target. **Trigger:** weekly exit criteria on counts/coverage missed.

### R10 Insufficient documentation
**Description:** docs drift from code; report artefacts missing at the end. **P:** M. **I:** M. **Mitigation:** [06-documentation-plan.md](06-documentation-plan.md), docs-check script, artefacts generated by commands. **Fallback:** M3 day 5 afternoon reserved for docs. **Trigger:** docs-check red or a page older than a week in an active area.

### R11 External-service dependency during demo
**Description:** the demo relies on a mail provider, Pusher, cloud storage or internet DNS. **P:** L. **I:** H. **Mitigation:** Mailpit, RustFS, webhook-echo, Reverb and `*.localhost` are all local; offline rehearsal with Wi-Fi off. **Fallback:** screen recording. **Trigger:** any `https://` to a third party in the demo path.

### R12 University deadline
**Description:** the compressed plan slips into report-writing time. **P:** M. **I:** H. **Mitigation:** M3 has two buffer days; report artefacts are generated continuously; chapter mapping exists. **Fallback:** cut list; the report describes deferred items as future work with evidence of design. **Trigger:** buffer consumed before M3 day 3.

### R13 RustFS 1.0 immaturity
**Description:** RustFS 1.0.0 shipped the day before research; presigned PUT, CORS or HEAD behaviour may differ. **P:** M. **I:** M. **Mitigation:** pin exact tag; M1-04 tests the presigned flow against RustFS *and* Garage in Compose. **Fallback:** Garage as default; `local` disk for dev only if both fail (never for on-prem). **Trigger:** upload flow fails in M1-04.

### R14 TanStack Table v9 documentation gap
**Description:** shadcn data-table docs target v8. **P:** M. **I:** M. **Mitigation:** half-day box for porting; server mode is conceptually identical. **Fallback:** `@tanstack/react-table@8`. **Trigger:** DataTable not paging/sorting from search params by end of M1 day 5 (M1-14).

### R15 TypeScript 7 tooling gaps
**Description:** a tool (Storybook, typescript-eslint, a codegen) needs the TS programmatic API. **P:** L. **I:** L. **Mitigation:** Biome only; openapi-typescript does not use the TS API. **Fallback:** TS 6.x pin. **Trigger:** a required tool fails on TS 7.

### R16 Scramble inference gaps
**Description:** resources with conditional fields or polymorphic authors produce `string` types. **P:** M. **I:** L. **Mitigation:** conventional resources; explicit casts; per-route overrides. **Fallback:** hand-annotate the ≤ 10 affected routes. **Trigger:** generated `schema.d.ts` shows `string` for known typed fields.

### R17 Solo developer illness/burnout
**Description:** 15 intensive days with one person. **P:** M. **I:** H. **Mitigation:** daily stopping rule, weekends off, tasks ≤ 1 day so a lost day costs one task, buffer days. **Fallback:** cut list; extend by the lost days if the university date allows. **Trigger:** two consecutive days lost.

### R18 Version drift before start
**Description:** a package major lands between research and M1 day 1. **P:** L. **I:** L. **Mitigation:** constraints pinned to researched majors; re-verify `versions.md` on day 1 only. **Fallback:** stay on researched versions. **Trigger:** install pulls a different major.

### R19 Data model rework mid-milestone 2
**Description:** SLA or automation needs columns/tables not anticipated, forcing wide migrations. **P:** M. **I:** H. **Mitigation:** [entities.md](../docs/08-database/entities.md) reviewed against all four algorithm pages on M1 day 5; timers, explanations, term stats and text features tables created in M1 even if empty. **Fallback:** additive migrations only; never rename in M2. **Trigger:** a M2 migration touching > 3 existing tables.

### R20 Demo laptop failure
**Description:** hardware or OS failure on the day. **P:** L. **I:** H. **Mitigation:** second machine with images pulled and `demo-reset` run; USB with recording and screenshots. **Fallback:** recording. **Trigger:** none (standing).

### R21 Host layout friction
**Description:** the shared `.shp.localhost` session cookie, CORS with credentials between `app` and `api`, or Caddy `tls internal` trust blocks login. **P:** M. **I:** M. **Mitigation:** Caddy internal CA installed via `caddy trust`; `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` and CORS set in `.env.example`; M1-08 verifies login from `app` to `api` in two browsers. **Fallback:** `HOST_LAYOUT=single` in development (SPA and API on one host) until fixed. **Trigger:** login from `app` to `api` fails in M1.

### R22 Stale tenant context in workers
**Description:** Horizon workers keep tenant A context into tenant B's job or a central job. **P:** M. **I:** H. **Mitigation:** `QueueTenancyBootstrapper` with `$forceRefresh = true`, `TenantAwareJob`, isolation item 6, permission cache reset on switch. **Fallback:** dedicated worker per tenant in demo (never in product). **Trigger:** isolation item 6 red or a notification delivered to the wrong tenant.

## Cut list order (when the M3 buffer is consumed)

1. Reverb realtime (keep polling)
2. Audit log viewer UI (data still written)
3. Idempotency-Key
4. CSV export job
5. Custom roles UI
6. Bulk actions
7. Password reset (invitations still work)
8. Hungarian batch mode and BM25 comparison in experiments
9. OpenTofu reduced to compute + DNS
10. Sixth dashboard chart (SLA trend) → five charts

Never cut: tenant isolation tests, RLS, the four algorithms with explanations and one experiment each, OpenAPI docs, webhooks with signatures, demo seed, E2E golden path.

## Weekly risk review ritual (Friday, 30 minutes)

1. Re-score P/I for each risk; note triggers fired.
2. Check weekly exit criteria ([definition-of-done.md](../docs/10-quality/definition-of-done.md)).
3. Apply the cut list if the buffer is consumed; record cuts in [09-v1-backlog.md](09-v1-backlog.md) with date and reason.
4. Update [08-decisions-open-questions.md](08-decisions-open-questions.md).
5. Write a five-line week note in `roadmap/notes/<date>.md` (built, measured, deviated, next).
