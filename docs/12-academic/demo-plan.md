# Demo plan

A 22-minute scripted demonstration that works offline (only the Compose stack on the presenter's laptop) and shows each academic point once. Rehearse against the seeded dataset; `just demo-reset` restores it in under a minute.

## Preconditions

- `docker compose --profile demo up -d` (`just up demo`) on the laptop; `just demo-reset` run beforehand.
- Hosts under `shp.localhost` resolve automatically (`app`, `api`, `admin`, `monitor`, `docs`); workspaces are `app.shp.localhost/acme` and `app.shp.localhost/globex`. For a public demo use `app.shp.subhambhandari.com.np`.
- Mailpit open in a second tab (shows outgoing email without the internet).
- Webhook receiver: the bundled `tools/webhook-echo` container (prints signed deliveries and verifies the signature).
- Browser at 125 % zoom, light theme; switch to dark once to show theming.

## Script

| Min | Step | What the examiner sees | Academic point |
|---|---|---|---|
| 0–1 | Open platform admin; show two tenants | Tenant list, suspend/reactivate | Multi-tenancy |
| 1–3 | Log in as Priya (manager) at `acme…`; open Dashboard | KPIs, six charts, SLA compliance | Analytics |
| 3–5 | Log in as Arjun (agent) in another window; create ticket "Cannot login after password reset" | Duplicate suggestion appears with score breakdown before saving | Duplicate detection |
| 5–7 | Proceed; ticket page opens | Priority P2 with "Why?" panel (factor contributions); assignment to least-loaded skilled agent with ranking; SLA panel with due times | Priority + assignment + SLA |
| 7–9 | Add internal note, then public reply | Mailpit shows contact email; first-response SLA turns "met" | Comments, notifications |
| 9–10 | Set pending; show SLA paused; resume | Pause accounting | SLA state machine |
| 10–12 | Switch to a seeded ticket near breach; run `just demo-tick` (advances the clock 30 min) | Warning → breach notifications for agent and manager; timer badge turns red | SLA state machine, scheduler |
| 12–14 | As Priya, open Settings → Automation; change a weight; preview | Scores change live | Configurability |
| 14–16 | Settings → Developer; show OAuth client and webhook subscription; resolve the ticket | webhook-echo prints `ticket.resolved` with verified signature; delivery log in UI; retry button | Developer platform |
| 16–17 | Open `docs.shp.localhost` | Interactive OpenAPI docs | API documentation |
| 17–18 | Log in as Globex user; paste the Acme ticket URL | 404; explain isolation tests | Tenant isolation |
| (+2) | As Priya open Reports → Backlog over time → drill into a day → open a ticket's 360 page → view it as of three days ago | History reconstruction, interval analytics | Reporting |
| 18–19 | Show `experiments/results/` plots and CI test summary | Fairness table, PR curve, sensitivity chart | Result analysis |
| 19–20 | Toggle dark mode; keyboard-navigate a dialog | Theming, accessibility | Design system |

## Failure fallbacks

- Realtime down → UI still works via polling; mention it.
- Reverb not started → same.
- Browser issue → second browser profile prepared.
- Laptop failure → screenshots and a screen recording of the full script on a USB stick.

## Seed data required (see roadmap/11-demo-dataset.md)

Two tenants; Acme with 8 agents in 3 teams, 6 skills, 6 categories, ~120 tickets across all statuses/priorities, 5 near-breach timers, 6 duplicate clusters, one webhook subscription pointed at webhook-echo; Globex minimal.
