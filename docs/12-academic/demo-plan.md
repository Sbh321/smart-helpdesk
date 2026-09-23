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
| 0–1 | Platform admin: `admin.shp.localhost` redirects to the platform sign-in; sign in as `admin@platform.test` | The two workspaces in the tenant list; suspend/reactivate through the platform API reference (`/platform-api/docs`) | Multi-tenancy |
| 1–3 | Priya: Dashboard | Eight KPIs with the previous period, six charts; SLA compliance ≈ 82 %, 26 breaches in 30 days | Analytics |
| 3–5 | Arjun: Tickets → New ticket, title "Cannot login after password reset", description "After the password reset the portal login fails with ERR-401 and I cannot log in." | Duplicate panel suggests #1031 (80 %) with the shared words before saving | Duplicate detection |
| 5–7 | Contact Laura Schmidt (Umbrella Health, enterprise), Account access, impact 2, urgency 3 → Create; the ticket (#1121) opens | P2 High, score 51.7, "Why this priority?" with the four factor contributions; assigned to Chen Wei; SLA panel: first response due in 2 h, resolution in 24 h. Then Priya opens an untriaged open ticket (e.g. #1099) → **Assignment**: the ranking (load, last assigned) and the eight excluded agents with reasons (Elena away, Grace offline, Farid at capacity, missing skills) → Auto-assign | Priority + assignment + SLA |
| 7–9 | Arjun: internal note, then public reply | Mailpit shows "[#1121] Cannot login after password reset" to Laura; first response turns "Met" | Comments, notifications |
| 9–10 | Set pending; show the SLA paused; Resume work | Pause accounting (resolution due time moves by the paused time) | SLA state machine |
| 10–12 | Priya: open #1104 "Cannot add new team members" (P1, resolution warning, due in about 20 min); run `just demo-tick 30` | The timer is breached, #1105's first response warns; the bell shows "A ticket has breached its SLA" for Chen and Priya; webhook-echo prints `ticket.sla_breached` | SLA state machine, scheduler |
| 12–14 | Meera: Settings → Priority automation; change a weight (impact 0.30, tier 0.25); Preview scores | The worked examples' scores change live; nothing is saved | Configurability |
| 14–16 | Meera: Settings → API clients ("Monitoring bridge") and Webhooks → Deliveries (one dead after six attempts, one retried; Retry button); Arjun resolves #1121 with a comment | webhook-echo prints `ticket.resolved` with `"verified": true`; the delivery appears in the log | Developer platform |
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

Open points found in the rehearsal (the same list is in the M3-13 report):

- The docs host loads Stoplight Elements from `unpkg.com`: the only step that needs the internet. Offline, show the committed `backend/openapi.json` or a screenshot.
- The platform console has a sign-in page, a guard, sign-out and a read-only tenant list (2026-09-23); creating, editing, suspending and reactivating workspaces from the console is still V1-PL-13, so step 0–1 does those through the platform API.
- Timeline entries of SLA events render `at: function at() …` and `details: [object Object]`, and assignment entries show raw ids (ticket timeline polish, M3-03).
- An automatically assigned ticket does not show its stored ranking; the Assignment dialog shows it only for an unassigned ticket, hence #1099 in step 5–7.
- Backlog over time has no drill-down; the drill-down is shown on Ticket volume.
- Fixed in the source but not yet in the rebuilt proxy image the screenshots come from: the SLA panel did not refresh after a status change (pending looked "Running"), showed "1h 60m", "Remaining Finished" and uncoloured state badges.

## Failure fallbacks

- Realtime down → UI still works via polling; mention it.
- Reverb not started → same.
- Browser issue → second browser profile prepared.
- Laptop failure → screenshots and a screen recording of the full script on a USB stick.

## Seed data required (see roadmap/11-demo-dataset.md)

Two tenants; Acme with 8 agents in 3 teams, 6 skills, 6 categories, 120 live tickets (#1001–#1120) across all statuses and priorities after 180 closed ones, 6 near-breach timers, 6 duplicate clusters, one OAuth client, one webhook subscription pointed at webhook-echo; Globex with 2 users and 8 tickets.
