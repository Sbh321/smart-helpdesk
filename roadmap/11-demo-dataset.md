# Demo dataset

Deterministic seed for the demonstration ([demo-plan](../docs/12-academic/demo-plan.md)), E2E tests and manual QA. Built in task M3-13; the generator is shared with the experiments ([evaluation-methodology](../docs/05-algorithms/evaluation-methodology.md)).

## As built (M3-13, 2026-09-22)

Code: `backend/app/Modules/Demo` (`DemoCatalogue` literal identities, `DemoPlan` pure seeded timeline, `DemoWorkspaceBuilder` replay, `DemoReset`, `DemoTick`, commands `demo:reset` and `demo:tick`), `database/seeders/DemoSeeder` (= `demo:reset`). Tests: `tests/Unit/Demo/DemoPlanTest.php`, `tests/Feature/Demo/DemoDatasetTest.php`. Rehearsal script: `tools/demo-rehearsal/rehearse.mjs` ([demo-plan](../docs/12-academic/demo-plan.md) §Rehearsal).

**How it runs.** `demo:reset` deletes the `acme` and `globex` tenant rows by id (every application-plane table cascades; referential actions are not filtered by row-level security and the change-capture trigger skips a deleted tenant's rows), then replays `DemoPlan` through the real actions (`ProvisionTenant`, `CreateTicket`, `AssignTicket`, `AddComment`, `TransitionTicket`, `OverrideTicketPriority`, `AutoCloseTicket`, `SaveContact`, `ChangeUserRoles`, `Settings::update`, `CreateApiClient`, `SaveWebhookSubscription`, `RegisterUpload`/`CompleteUpload`, `RecordDeliveryOutcome`) as the application role, inside each workspace, with the acting user set so audit rows and change capture name the right actor. Time is one frozen instant moved step by step (`ReplayClock`: the `Clock`, Carbon's test "now" and the session setting `app.occurred_at`, which the change-capture trigger now honours — Reporting migration `2026_09_21_190000_change_capture_replay_time`). Before every step the SLA sweep runs at each minute a timer warns or breaches (`EvaluateSlaTimers` for this workspace only, no heartbeat). The workspaces are suspended while they are built, so the real scheduler's sweeps leave them alone. Queued side effects of the replay (mail, broadcasts, webhook HTTP calls, report refresh jobs, notifications) go to a discarding queue; afterwards the webhook deliveries get their outcomes through `RecordDeliveryOutcome` at their own instants, a curated set of notifications is written, and `reports:rebuild` runs once. `reports:verify --days=90` reports no drift.

**Measured:** `demo:reset` 33–36 s on the dev stack (drop 0.3 s, replay 27–30 s, reports 4–5 s) with 180 history tickets; about 25 s in the test suite with 10.

| Planned | As built |
|---|---|
| Truncate as the owner role; clear Valkey and Horizon queues | Delete only the two demo tenants, as the application role; queues and Valkey are shared with other workspaces and are left alone (a stale queued job of a deleted workspace fails harmlessly) |
| Refuse when `APP_ENV=production` | Refuse in production unless `DEMO_INSTANCE=true` **and** `--force` (the public demo instance resets deliberately); `DEMO_PASSWORD` replaces `password` there |
| Anchor ticket numbers | Literal, as planned: numbers 1001–1120 in creation order (the counter starts at `1001 − history`), anchors at their numbers; 180 history tickets are #821–#1000 |
| About 600 tickets over 90 days | 180 closed history tickets (days −90 to −30) + 120 live (`DEMO_HISTORY_TICKETS`, `--history`); 600 would take about 60 s |
| Distributions ±2 | Exact: status 18/22/30/12/24/14, priority 10/30/55/25, categories 22/14/34/18/20/12, age 25/35/35/25, via ui 100 / api 20. Each ticket gets the impact and urgency that make the real `PriorityStrategy` compute its level for its contact's tier at that moment |
| Agent loads Deepa 9/12, Chen 1/8, … | Emerge from the replay (least-loaded assignment): Arjun 10/12, Asha 7/10, Bikram 8/10, Chen 10/12, Deepa 12/14, Elena 6/8 (away from day −2), Farid 10/10, Grace 1/10 (offline from day −6). Capacities raised (Arjun 12, Chen 12, Deepa 14, Farid 10) and Farid got `accounts` 1 so the golden-path ticket (Account access) has an eligible agent and a ranking of two. Priya has an Agent profile without skills (golden-path E2E) |
| 18 open tickets | Priya switched automatic assignment off 23 h ago for a triage review and on 12 min ago (two `settings.updated` audit rows); the 25 tickets of the last day arrived in that window, the urgent ones were triaged by hand (auto-assign button, then a hand pick when nobody was eligible) |
| Exactly 3 breached timers | #1101 resolution, #1102 first response, #1103 resolution (after Priya's P1 override "SLA escalation: first response breached"; its first response is met late) plus realistic breaches: first response of the 5 oldest untriaged open tickets and one slow ticket (#1077): 9 breached timers on active tickets. The SLA policy targets were relaxed by Meera on day −30 (P1 30/480, P2 120/1440, P3 480/4320, P4 1440/10080 min) so the queue is mostly on time; dashboard SLA compliance ≈ 82 % |
| 5 near-breach timers | 6 due within 40 min: #1104 resolution (warning, 19 min), #1105 first response (running, 34 min), #1062–#1064 resolution (warning, 24–34 min), #1026 resolution (warning, 20 min) |
| Six clusters, suggestions stored | All six later members carry the earlier one (Jaccard 0.46–0.67); the golden-path text finds #1031 at 0.80 |
| Contact emails of public replies in Mailpit during seeding | Not sent (discarded queue); Mailpit is cleared on reset, so only the demo's own replies appear |
| 6 webhook deliveries 4/1/1 | The dev subscription (`http://webhook-echo:9100/hook`, secret `DEMO_WEBHOOK_SECRET`, also webhook-echo's default) is created 20 h ago and receives every subscribed event after it: 45 deliveries, the first dead after 6 attempts (503), the second failed once and succeeded, the rest succeeded. Only when `webhook-echo` is an allowed development host; never in production |
| Notifications 3–8 per agent, 2 unread | As planned: each agent's last four assignments and last two SLA warnings and breaches; Priya the last three breaches and the #1103 escalation; all but the newest two read |
| Audit rows | `tenant.created` (platform), `user.role_changed` (Priya promoted to manager on day −60), `sla_policy.updated`, `settings.updated` ×2, `api_client.created`, `webhook.created`, `organization.updated` (Hooli → premium, day −20), `agent.profile_changed` ×2 (Grace offline, Elena away), `agent.shifts_changed` ×8, `ticket.priority_overridden` (#1103) |
| History events | Rahul Verma moves Initech → Hooli (day −25) → Stark Logistics (day −12); Farid joins Customer Success (day −10); Hooli upgraded (day −20); SLA policy edit (day −30) |
| Attachments | `screenshot.png` (generated with GD) and `error.log` on internal notes of #1031 and #1090 through intent → object → complete; skipped with a warning when object storage is unreachable (`--no-attachments`) |
| Platform admin `sam@platform.test` | `admin@platform.test` (the existing development admin); outside production its password is set to the demo password |
| `demo:tick` shifts `created_at` of open tickets and runs the priority pass | Shifts only the unfinished SLA timers (start, warning point, due time, open pause) of the two demo workspaces and runs the SLA sweep for them; ticket history is not rewritten, and the hourly ageing pass stays with the scheduler |
| `just demo-verify` | Not a recipe: the acceptance checks are `DemoDatasetTest` (counts, distributions, anchors, SLA states, near-breach timers, stored clusters, the DuplicateStrategy on the golden text, explained assignments, capacity, notifications, integrations, history instants, `reports:verify`, determinism, isolation, production refusal, `demo:tick`) |
| `demo` Compose profile | Unchanged: `webhook-echo` is already in the `dev` and `demo` profiles; nothing else is needed |

## Design rules

1. **Fixed seed, fixed identities.** `DemoSeeder` uses `SeededRandom(2026)`; emails, slugs, team names and the 20 anchor tickets are literal constants, so the demo script never changes.
2. **Fresh timestamps.** All times are offsets from `now()` at seed time (`created_at = now − 3 days 2 h`), computed through the `Clock`, so SLA timers are always "about to breach" on demo day.
3. **Algorithm-consistent state.** Assignments, priority levels and duplicate suggestions are produced by running the real actions (`CreateTicket` with automation on) rather than inserting columns, so the "Why?" panels are genuine.
4. **Offline.** No external hosts: mail to Mailpit, webhooks to `webhook-echo`, storage to RustFS, realtime to local Reverb if enabled.
5. **Idempotent reset.** `just demo-reset` truncates application tables (owner role), re-runs the seeder, clears caches and queues, and prints the login table.

## Tenants and users

| Tenant | Slug / host | Purpose |
|---|---|---|
| Acme Support | `acme` → `app.shp.localhost/acme` | full dataset |
| Globex Retail Support | `globex` → `app.shp.localhost/globex` | minimal: 2 agents, 5 contacts, 8 tickets; proves isolation |
| Platform | `admin.shp.localhost` | super admin |

All passwords are `password` (dev/demo only; the seeder refuses to run when `APP_ENV=production`).

| Email | Tenant | Role | Note |
|---|---|---|---|
| `sam@platform.test` | platform | Platform Super Admin | |
| `meera@acme.test` | acme | Tenant Owner | settings, developer tab |
| `dev@acme.test` | acme | Developer | owns the OAuth client |
| `priya@acme.test` | acme | Support Manager | dashboard, assignment overrides |
| `arjun@acme.test` | acme | Support Agent | golden-path agent |
| `sam@globex.test` | globex | Tenant Admin | isolation negative |

## Acme organisation

**Skills (6):** `billing`, `refunds`, `technical`, `networking`, `accounts`, `onboarding`.

**Teams (3):** Billing, Technical, Customer Success.

**Categories (6) → required skills, default team:** Billing question (`billing`) → Billing; Refund request (`billing`, `refunds`) → Billing; Technical issue (`technical`) → Technical; Network / connectivity (`technical`, `networking`) → Technical; Account access (`accounts`) → Customer Success; Onboarding (`onboarding`) → Customer Success.

**Agents (8):**

| Agent (email) | Teams | Skills (level) | Capacity | Availability | Seeded active load |
|---|---|---|---|---|---|
| Arjun `arjun@acme.test` | Technical | technical 3, networking 2 | 10 | available | 4 |
| Asha `asha@acme.test` | Billing | billing 3, refunds 2 | 10 | available | 3 |
| Bikram `bikram@acme.test` | Billing | billing 2, refunds 3 | 10 | available | 6 |
| Chen `chen@acme.test` | Billing, Customer Success | billing 3, accounts 2 | 8 | available | 1 |
| Deepa `deepa@acme.test` | Technical | technical 2, networking 3 | 12 | available | 9 |
| Elena `elena@acme.test` | Customer Success | accounts 3, onboarding 3 | 8 | away | 2 |
| Farid `farid@acme.test` | Technical, Customer Success | technical 1, onboarding 2 | 6 | available | 5 (near cap) |
| Grace `grace@acme.test` | Customer Success | onboarding 3, accounts 1 | 10 | offline | 0 |

The spread (Deepa overloaded, Chen idle, Elena away, Grace offline, Farid near capacity) makes the assignment shortlist and exclusion reasons visible in the demo.

**Organisations (6) with tiers:** Globex Retail (enterprise), Initech (premium), Umbrella Health (enterprise), Hooli (standard), Stark Logistics (premium), Wayne Foods (standard). **Contacts:** 30, five per organisation, names from a fixed list, unique emails `<first>.<last>@<org-domain>.test`; 3 contacts without an organisation.

## Tickets (≈ 120 in Acme)

Distribution targets (generator asserts them within ±2):

| Dimension | Distribution |
|---|---|
| Status | open 18, assigned 22, in_progress 30, pending 12, resolved 24, closed 14 |
| Priority level | P1 10, P2 30, P3 55, P4 25 |
| Category | Billing 22, Refund 14, Technical 34, Network 18, Account 20, Onboarding 12 |
| Age | 0–1 day 25, 1–3 days 35, 3–7 days 35, 7–30 days 25 |
| Created via | ui 100, api 20 |

**Anchor tickets (literal):**

| # | Title | Category | Role in demo |
|---|---|---|---|
| 1031 | Login fails after resetting password (ERR-401) | Account access | duplicate target for the golden-path ticket |
| 1032 | Cannot log in after password reset — ERR-401 on portal | Account access | cluster A member |
| 1040 | Invoice PDF downloads as a blank page | Billing | cluster B seed |
| 1041 | Blank PDF when exporting invoice | Billing | cluster B |
| 1055 | VPN disconnects every 10 minutes on Windows 11 | Network | cluster C seed |
| 1056 | Windows 11 VPN keeps dropping every ten minutes | Network | cluster C |
| 1060 | Refund for duplicate charge on 12 Sep | Refund | cluster D seed |
| 1061 | Charged twice on 12 September, requesting refund | Refund | cluster D |
| 1072 | Two-factor code SMS never arrives | Account access | cluster E seed |
| 1073 | Not receiving 2FA text messages | Account access | cluster E |
| 1080 | API returns 500 on POST /orders since v2.3.1 | Technical | cluster F seed |
| 1081 | POST /orders 500 error after upgrading to v2.3.1 | Technical | cluster F |
| 1090 | Complete outage: dashboard unreachable for all users | Technical | P1, enterprise, all users — priority example B |
| 1095 | Typo on the pricing page footer | Onboarding | P4 example A |
| 1101 | Payment gateway timeout at checkout for EU customers | Technical | breached resolution timer |
| 1102 | Data export stuck at 99 % for three days | Technical | breached first response |
| 1103 | Wrong VAT rate applied to Irish invoices | Billing | breached, escalated to P1 by SLA |
| 1104 | Cannot add new team members — seat limit error | Account access | near-breach, used with `demo-tick` |
| 1105 | Onboarding call never scheduled after signup | Onboarding | near-breach |
| 1110 | Reopened: refund not received after approval | Refund | reopened example, resolution cycle 2 |

The remaining ~100 tickets are generated from `TicketCorpus` templates (30 title templates × category vocabulary) with seeded variation. Six duplicate clusters (A–F above) each contain 2–3 members; cluster members are created minutes apart so suggestions appear in the timeline of the later ticket.

**SLA state:** 5 near-breach timers (due within 20–40 minutes of seed time: #1104, #1105 and three generated), 3 breached (#1101 resolution, #1102 first response, #1103 both; escalation already raised #1103 to P1 with an audit row), everything else running or met consistent with status.

**Comments and attachments:** every non-open ticket has 1–4 comments (public reply by agent, internal note, contact reply); #1031 and #1090 carry attachments (`screenshot.png` 120 KB, `error.log` 8 KB) uploaded to RustFS through the real intent/complete flow. Contact emails for public replies land in Mailpit during seeding (Mailpit is cleared first).

**Integrations:** OAuth client "Monitoring bridge" (scopes `tickets:read tickets:write`, secret printed once by the seeder); webhook subscription to `http://webhook-echo:8080/hook` for `ticket.created`, `ticket.status_changed`, `ticket.resolved`, `ticket.sla_breached` with 6 seeded deliveries (4 succeeded, 1 failed then succeeded on retry, 1 dead after 5 attempts).

**Audit and notifications:** audit rows for tenant creation, role changes, SLA policy edit, settings change, client creation, webhook creation, #1103 escalation; each agent has 3–8 notifications (2 unread) covering assignment, SLA warning and breach.

**Globex:** 2 agents, 1 team, 5 contacts, 8 tickets, one webhook subscription; enough to prove isolation and the platform tenant list.

## Mechanism

| Command | What it does |
|---|---|
| `just demo-reset` | `docker compose exec app php artisan demo:reset --force`: assert non-production, truncate application-plane tables as owner role, clear Valkey and Horizon queues, clear Mailpit and webhook-echo logs, run `DemoSeeder` (`--seed=2026`), warm permission cache, print login table and the OAuth client secret |
| `just demo-tick 30m` | `php artisan demo:tick 30m`: shifts `started_at`, `warning_at`, `due_at`, `paused_at` of all Acme timers and `created_at` of open tickets backwards by the interval, then runs `sla:evaluate` and the priority pass once. Guarded by `APP_ENV != production` and the `demo` compose profile. Used in E2E-07 and the demo step at minute 10 |
| `just demo-verify` | acceptance checks below |

`DemoSeeder` composes the same factories used in tests and the same `TicketCorpus` used by `experiment:generate-workload`; the experiments call the generator with `--seed=42` and `--tickets=500` and a different agent roster, so demo and experiment data share vocabulary but not rows.

## Acceptance checks (`demo:verify`)

| Check | Expectation |
|---|---|
| Counts | tenants 2, Acme users 8 + 3 staff, agents 8, contacts 30 (+3), tickets 118–122, Globex tickets 8 |
| Distributions | status/priority/category within ±2 of targets |
| Duplicates | preview for "Cannot login after password reset (ERR-401)" returns #1031 with score ≥ 0.55; six clusters have stored suggestions |
| Assignment | all `assigned`/`in_progress` tickets have an assignment row with explanation JSON; no agent above capacity; Grace and Elena hold no auto-assigned open tickets |
| SLA | exactly 3 breached timers, 5 warning-or-due-within-40-min timers; #1103 level = P1 with override reason `sla_escalation` |
| Notifications | each Acme agent has ≥ 3 notifications, ≥ 1 unread |
| Integrations | client credentials token can be obtained; 6 deliveries with states 4/1/1 |
| Isolation | Globex user cannot fetch #1031 (404) |
| Idempotence | running `demo-reset` twice yields identical counts and identical anchor ticket numbers |
| Time | seed completes in < 90 s |

## Demo-day checklist

1. `just demo-reset` the evening before and again 30 minutes before.
2. Open Mailpit, webhook-echo log, and both workspaces (`app.shp.localhost/acme`, `app.shp.localhost/globex`) in separate browser profiles.
3. Rehearse `just demo-tick 30m` once; reset again.
4. USB stick with the screen recording and screenshots from `docs/exports/`.

## History for reports (added 2026-09-17)

Reports and as-of views need history, so the demo seed does not insert final states directly. `DemoSeeder` replays a scripted 90-day timeline through the real actions under a `FrozenClock` that advances event by event (tickets created, assigned, replied, pending, resolved, reopened; contacts edited; an organisation's tier upgraded on day −20; an agent moved between teams on day −10; SLA policy edited on day −30), so `entity_changes`, `ticket_events`, intervals, facts and daily snapshots are all genuine. `reports:rebuild` then runs once and `reports:verify` must report zero drift. Target volume: about 600 tickets in Acme over 90 days (the 120 "live" tickets described above are the most recent part).

