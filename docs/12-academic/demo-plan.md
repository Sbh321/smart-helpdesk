# Demo plan

A 22-minute scripted demonstration that works offline (only the Compose stack on the presenter's laptop) and shows each academic point once. Rehearse against the seeded dataset; `just demo-reset` restores it in under a minute.

## Preconditions

- `docker compose --profile demo up -d` (`just up demo`) on the laptop; `just demo-reset` run beforehand (about 35 s; it prints the logins and the OAuth client secret). The dataset is described in [roadmap/11-demo-dataset.md](../../roadmap/11-demo-dataset.md) §As built.
- Hosts under `shp.localhost` resolve automatically (`app`, `api`, `admin`, `monitor`, `docs`); workspaces are `app.shp.localhost/acme` and `app.shp.localhost/globex`. For a public demo use `app.shp.subhambhandari.com.np` (reset there with `demo:reset --force` on an instance with `DEMO_INSTANCE=true`).
- Four browser profiles signed in beforehand: **Priya** (`priya@acme.test`, Manager), **Arjun** (`arjun@acme.test`, Agent), **Meera** (`meera@acme.test`, Owner: the Manager role has no `settings.manage` or `integrations.manage`, which is worth saying out loud), **Sam** (`sam@globex.test`). Password `password`.
- Mailpit open in a tab (`mail.shp.localhost`); `docker compose logs -f webhook-echo` in a terminal (the seeded subscription's secret is webhook-echo's default, so deliveries verify).
- Browser at 125 % zoom, light theme; switch to dark once to show theming.

## Script

| Min | Step | What the examiner sees | Academic point |
|---|---|---|---|
| 0–1 | Platform admin: `admin.shp.localhost/platform-api/tenants` (signed in as `admin@platform.test`) | The two workspaces as JSON; suspend/reactivate through the platform API reference (`/platform-api/docs`) | Multi-tenancy |
| 1–3 | Priya: Dashboard | "Right now" (open, unassigned, SLA due soon, breached: each a link to that queue view), then eight KPIs for the period with sparklines, then trends and breakdowns; SLA compliance ≈ 82 %, 26 breaches in 30 days | Analytics |
| 3–5 | Arjun: Tickets → New ticket, title "Cannot login after password reset", description "After the password reset the portal login fails with ERR-401 and I cannot log in." | Duplicate panel suggests #1031 (80 %) with the shared words before saving | Duplicate detection |
| 5–7 | Contact Laura Schmidt (Umbrella Health, enterprise), Account access, impact 2, urgency 3 → Create; the ticket (#1121) opens | P2 High, score 51.7; "Why this priority?" says it in a sentence ("Most of it comes from urgency and organisation tier") with a bar per factor, and "Show the calculation" opens the exact table; assigned to Chen Wei, and Priya sees why in the Assignment section ("the only eligible Agent …", ranking behind "Show the ranking"); header: Response and Resolution timers in words. Then Priya opens an untriaged open ticket (e.g. #1099) → **Assignment**: the ranking (load, last assigned) and the eight excluded agents with reasons (Elena away, Grace offline, Farid at capacity, missing skills) → Auto-assign | Priority + assignment + SLA |
| 7–9 | Arjun: internal note, then public reply | Mailpit shows "[#1121] Cannot login after password reset" to Laura; first response turns "Met" | Comments, notifications |
| 9–10 | Set pending; show the SLA paused; Resume work | Pause accounting (resolution due time moves by the paused time) | SLA state machine |
| 10–12 | Priya: open #1104 "Cannot add new team members" (P1, resolution warning, due in about 20 min); run `just demo-tick 30` | The timer is breached, #1105's first response warns; the bell shows "A ticket has breached its SLA" for Chen and Priya; webhook-echo prints `ticket.sla_breached` | SLA state machine, scheduler |
| 12–14 | Meera: Settings → Priority automation; change a weight (impact 0.30, tier 0.25); Preview scores | The worked examples' scores change live; nothing is saved | Configurability |
| 14–16 | Meera: Settings → API clients ("Monitoring bridge", scopes in words) and Webhooks → Deliveries → Status: Gave up → Details (error in words, attempts, payload as JSON, Retry); Arjun resolves #1121 with a comment | webhook-echo prints `ticket.resolved` with `"verified": true`; the delivery appears in the log | Developer platform |
| 16–17 | Meera: `docs.shp.localhost` | Interactive OpenAPI reference (needs the internet, see §Rehearsal) | API documentation |
| 17–18 | Sam (Globex): paste the Acme ticket URL, then the API URL | "not found" page and `404` from the API; explain the isolation tests and row-level security | Tenant isolation |
| (+2) | Priya: Reports → Backlog over time (90 days of genuine history), then Ticket volume → click a day's count → the records behind it → #1031 → History → as of three days ago | The drill-down list; the then/now diff (Pending → In progress, the requester's reply) | Reporting |
| 18–19 | Show `experiments/results/` plots and CI test summary | Fairness table, PR curve, sensitivity chart | Result analysis |
| 19–20 | Toggle dark mode; keyboard-open the New ticket dialog | Theming, accessibility | Design system |

## Rehearsal

`tools/demo-rehearsal/rehearse.mjs` plays the script with Playwright against the running stack (four browser contexts, `demo:tick` through `docker compose`), saves one screenshot per step in [screenshots/](screenshots/) and a result file per run (`rehearsal-1.json`, `rehearsal-2.json`), and lists every host outside the platform that the pages contacted:

```sh
just demo-reset && node tools/demo-rehearsal/rehearse.mjs --run 1
just demo-reset                       # again before the real demo: the rehearsal creates #1121 and ticks the clock
```

Rehearsed twice on 2026-09-22 on the dev stack: 25 of 25 steps passed both times; `demo:reset` took 40–43 s while the backend suite ran on the same machine (33–36 s otherwise). Machine time per run was 69 s; with talking, the timings in the table above hold (the extra sign-ins are prepared browser profiles). Screenshots are from run 2.

Open points found in the first rehearsal (2026-09-22) and where they stand after the redesign (milestone 4, re-rehearsed 2026-09-24):

- The docs host loads Stoplight Elements from `unpkg.com`: still the only step that needs the internet. Offline, show the committed `backend/openapi.json` or a screenshot.
- The platform console page is still a placeholder (V1-PL-13): step 0–1 uses the platform API.
- ~~Timeline entries render `[object Object]` and raw ids~~: fixed by the shared record-value formatter (M4-03).
- ~~An automatically assigned ticket does not show its stored ranking~~: the Assignment section shows why the Agent was chosen and the ranking at decision time (M4-06); #1099 is still used to show the live ranking before a decision.
- Backlog over time has no drill-down (its numbers are end-of-day counts); the drill-down is shown on Ticket volume.
- ~~SLA panel showed "1h 60m", "Remaining Finished" and did not refresh after a status change~~: one `SlaIndicator` in words, refreshed with the ticket (M4-07).

Re-rehearsal after the redesign: the script's selectors were updated to the new UI (the priority sentence and "Show the calculation", the assignment reason, the composer's audience-named send buttons, the delivery status filter and details, theme in the account menu), and it found one wording bug ("1 eligible Agents"), fixed.

## Failure fallbacks

- Realtime down → UI still works via polling; mention it.
- Reverb not started → same.
- Browser issue → second browser profile prepared.
- Laptop failure → screenshots and a screen recording of the full script on a USB stick.

## Seed data required (see roadmap/11-demo-dataset.md)

Two tenants; Acme with 8 agents in 3 teams, 6 skills, 6 categories, 120 live tickets (#1001–#1120) across all statuses and priorities after 180 closed ones, 6 near-breach timers, 6 duplicate clusters, one OAuth client, one webhook subscription pointed at webhook-echo; Globex with 2 users and 8 tickets.
