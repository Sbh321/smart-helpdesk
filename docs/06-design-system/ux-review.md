# UX review (2026-09-23)

A screen-by-screen review of the built SPA, written before the milestone 4 redesign ([roadmap/05-ui-revamp.md](../../roadmap/05-ui-revamp.md)). It records what the audit found, so the redesign tasks argue from evidence rather than taste. Design decisions themselves stay in [principles](principles.md), [tokens](tokens.md) and [components](components.md).

## Method

Read every route under `frontend/src/routes` (44 files), the 28 primitives in `components/ui`, the 25 compositions in `components/shared`, and the 24 rehearsal screenshots in [12-academic/screenshots](../12-academic/screenshots); measured token discipline with grep; checked the vendor boundary and the chart layer.

## What the audit found healthy

These need no work, and the redesign must not regress them.

| Area | Evidence |
|---|---|
| Token discipline (D1) | No raw Tailwind palette colour (`bg-gray-500` and friends) anywhere in `src`; 10 arbitrary pixel values in the whole app; 7 hex literals, all inside `lib/theme` where a hex is the input |
| One primitive layer (D2) | Zero `@base-ui` imports outside `components/ui`; Recharts confined to `features/reports/components/series-chart.tsx` behind `ChartCard`; `cmdk` only inside `CommandPalette` |
| Density (D4) | `data-density` comfortable/compact drives control heights and table row heights from tokens |
| Themes (D7) | Light, dark and system resolved before first paint; tenant brand colour derived into `--primary` with a contrast check (`pnpm tokens:check`, 104 pairs) |
| States (D8) | `EmptyState`, `ErrorState`, `ForbiddenState`, `NotFoundState` and skeletons exist and are used |
| Accessibility | 32 axe scans across 16 screens in light and dark, plus per-feature axe tests: zero violations |

**Consequence for the redesign:** this is not a "build a design system" initiative. The system exists and is enforced by tests. Milestone 4 is a **UX and information-design** initiative on top of it, plus the enforcement and documentation gaps below.

## Gaps

### G1 — No component workshop

There is no Storybook or equivalent. Components are exercised only through feature tests. Decided on 2026-09-23: **not adopted for the MVP** (dependency size against a 93 %-full disk, and the design system is already documented and test-covered); revisit as a V1 item after the component set settles.

### G2 — Boundaries are conventions, not rules

D1, D2 and D9 hold today by discipline alone. Nothing fails a build if a feature imports `@base-ui/react` directly, hard-codes `#fff`, or calls Recharts outside the chart layer. A lint rule turns three principles into guarantees.

### G3 — Information hierarchy is flat

Measured on the rehearsal screenshots.

| Screen | Finding |
|---|---|
| Dashboard | Eight KPI tiles of identical weight; each repeats a full-width link "Open the full report: …" (eight repetitions of the same seven words); charts sit below the fold with no summary |
| Report page | Filter bar wraps to two rows mixing five control shapes; totals render as five equal cards; the chart has no legend, no units and ISO dates (`2026-08-25`) on the axis; "Chart shows: Default" does not say what it will show |
| Ticket detail | The agent's context (status, priority, SLA, assignment) is a flat definition list in the right column, with the same visual weight for `Tags —` as for a breached SLA |
| Ticket queue | Columns carry number, title, status, priority, contact, category, assignee; **channel, SLA health and age are missing or hidden**, so the three facts that decide what an agent picks next are not on the row |

### G4 — Raw data leaks into the interface

The ticket timeline prints storage values: `team_id: null → 01a0c549-1f64-71de-933d-ef235eb3edd1`, `candidate_ticket_ids: 01a0c549-…,01a0c549-…`. The entity History tab (M3-21) already solved this with readable attribute names, resolved record names and enum labels; the ticket timeline was never migrated to it.

### G5 — Explainability is buried

Priority reasoning is a four-row table of `impact 0.3333 · 0.4 · 13.3333` inside a popover — the numbers are the algorithm's, but they are not an explanation a human reads. Assignment reasoning appears only while assigning, never afterwards on an assigned ticket (`assignment-dialog.tsx:57,115`). D5 calls these surfaces first-class; today they are technically present and practically unreadable.

### G6 — Thin chart vocabulary

28 reports declare only four chart kinds (`line`, `table`, `heatmap`, `stacked_area`; `bar` by default). No sparkline, distribution, capacity or progress representation exists, although the data for several does (agent load and capacity, ageing buckets, SLA remaining time).

### G7 — Global chrome spends prime space

The top bar holds an availability pill, three separate theme buttons (Light/Dark/System) and the avatar, while global search — the fastest route to any record — is a narrow box between them.

### G8 — Responsive below the laptop breakpoint

Documented as V1 in [accessibility](accessibility.md) (`Mobile/touch layouts below 768px | V1`), and the browser tests now run at 1280×800. Tablet width (768–1024) is untested: the sidebar stays fixed at 224 px and the ticket detail keeps three columns.

## Outcome (2026-09-24)

Milestone 4 closed every finding; each row names the task that did and what now enforces it.

| Finding | Outcome | Held by |
|---|---|---|
| G1 No component workshop | Not adopted (decision above); the compositions are documented in [components](components.md) and covered by feature and browser tests | browser suite |
| G2 Boundaries are conventions | Biome `noRestrictedImports` per layer and `scripts/check-design-tokens.ts` in `pnpm lint` (M4-01) | `pnpm lint` |
| G3 Flat hierarchy | Shell with grouped navigation and a rail (M4-02); ticket queue that answers "what next" (M4-04); ticket detail as a workspace (M4-05); dashboard in three levels (M4-08); readable settings (M4-12) | page patterns, browser tests |
| G4 Raw data in the interface | One formatter for record values in the timeline, History and audit (M4-03); dates in words on charts and tables, "No team" (M4-09); errors and statuses of deliveries in words (M4-11) | `record-values.test.ts`, `dimension-labels.test.ts` |
| G5 Buried explainability | Priority as a sentence and bars; assignment reason on the ticket; duplicate match details (M4-06); SLA state with policy and calendar (M4-07) | unit and browser tests |
| G6 Thin chart vocabulary | Legend always, stacked and upright bars, sparklines where the API names a series, report toolbar with chips (M4-08, M4-09); [data visualisation](data-visualization.md) | chart geometry tests, axe in both themes |
| G7 Chrome spends prime space | Theme and density in the account menu, search in the centre (M4-02) | app-shell tests |
| G8 Below the laptop breakpoint | Icon rail and ticket-context drawer below 1280 px, no sideways scroll at 1024 or 768 px (M4-13); [responsive](responsive.md) | 1024 px browser test with axe |

Also found and fixed on the way: the context panel's missing `h2` (heading order, M4-07), the as-of view that could be mistaken for the live record (M4-10), one-time secrets checked out of every cache (M4-11), an unused toolbar composition removed (M4-14), and "1 eligible Agents" (M4-14, found by the rehearsal). The healthy table above still holds: zero axe violations in the E2E scans and the per-feature scans, no palette colours outside the token layer.

## Non-goals for milestone 4

- Rebuilding the token layer, the primitive set or the theme architecture (they work; see the healthy table).
- A visual "brand refresh": colour and shape stay as documented, because the palette is already accessibility-validated and semantic.
- Mobile layouts below 768 px (stays V1).
- Backend changes, except where a screen provably cannot show what it must (each such case gets a line in the task block).

## Rules the redesign follows

1. **Evidence before change.** Every task names the finding (G1–G8) or the screen it fixes.
2. **No new fake affordances.** No button, chart or metric that the API cannot back ([definition of done](../10-quality/definition-of-done.md)).
3. **Tests move with the UI.** Browser and E2E selectors use visible text and roles, so wording changes are part of the task, not follow-up work.
4. **Zero axe regressions.** 32 E2E scans and the per-feature scans stay green; new surfaces add scans.
5. **One pattern per job.** A new page pattern goes into [page-patterns](page-patterns.md) before a second screen copies it.
