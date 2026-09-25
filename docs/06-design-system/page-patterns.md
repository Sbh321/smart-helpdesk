# Page patterns

Five layouts cover every screen in the SPA. A new page picks one and fills its slots; it does not invent a sixth. Pages: [principles](principles.md) · [components](components.md) · [spacing](spacing.md) · [UX review](ux-review.md). Introduced by roadmap [M4-01](../../roadmap/05-ui-revamp.md).

## Shell

The authenticated frame around every pattern (`components/layout`, roadmap M4-02).

```text
┌ nav ────────┬ header ─────────────────────────────────────────────┐
│ workspace   │ breadcrumbs   [ search · Ctrl K ]   actions · bell · │
│             │                                     account         │
│ Dashboard   ├─────────────────────────────────────────────────────┤
│ Tickets     │ main (the page pattern)                             │
│ RECORDS     │                                                     │
│ Contacts    │                                                     │
│ …           │                                                     │
│ [collapse]  │                                                     │
└─────────────┴─────────────────────────────────────────────────────┘
```

- **Navigation** is grouped: Work (no heading), Records, Insight, Administration. A group with no permitted items renders nothing, heading included. Collapsing leaves a 56 px icon rail where labels become tooltips and accessible names; the choice is stored per browser (`sh.nav-collapsed`) and applies from 1280 px; narrower windows start with the rail ([responsive](responsive.md)).
- **Header**: breadcrumbs (from `lg` up), then search in the centre as the widest control, then page actions, the connection indicator, notifications and the account menu. Theme and density live inside that menu (`AppearanceMenu`), not in the bar.
- Page actions belong to the page header, never the shell header.

## Shared slots

Every pattern is built from the same four bands, top to bottom.

| Slot | Component | Holds | Rules |
|---|---|---|---|
| Header | `PageHeader` | the `h1`, one sentence of description, page actions | exactly one per route, so headings stay `h1 → h2 → h3`; actions are what the page *is for*, never a filter |
| Toolbar | `FilterBar` (the `DataTable` toolbar, or a report's `ParameterBar`) | controls that change what is shown: quick views, filters, search, period, group-by; `FilterChips` for the filters in force; view actions (export, columns) at its end | state lives in the URL, so a view is shareable; "Clear filters" resets it |
| Content | pattern-specific | the data | one primary region per page; secondary regions never compete with it for weight |
| Panel | `SidePanelSection` | context beside the content | `details`-based, so keyboard and screen readers work without ARIA; at most two sections open by default |

Spacing between bands is `gap-6`; inside a band `gap-2` for controls and `gap-3` for blocks ([spacing](spacing.md)).

## List page

Tickets, contacts, organisations, notifications, audit log.

```text
PageHeader            title · description · primary action (New ticket)
FilterBar             quick views · filters · search        end: export, columns
  chips               active filters, each removable        note: result count
DataTable             sortable columns · selection · bulk actions · pagination
```

- The row carries the facts that decide the next action, not every field the API returns. For tickets that is number, subject, requester, channel, status, priority, SLA health, assignee, age ([ux-review G3](ux-review.md#g3--information-hierarchy-is-flat)).
- Weight does part of the work: the subject is the only `font-medium` cell; metadata is `text-muted-foreground`; badges are reserved for status, priority and SLA.
- Five states, with their own copy: loading (skeleton rows of the real shape), refreshing (the table stays, the toolbar shows the pending state), empty ("no tickets yet" plus the create action), no results ("no tickets match these filters" plus clear filters), error and forbidden.
- URL state: filters, sort, page and column visibility. Anything not in the URL is lost on reload and must not change what the table shows.

## Record page

Ticket, contact, organisation, agent, team, category.

```text
PageHeader            identity (number and subject) · state badges · primary actions
Tabs                  Details · Overview · History        (only where more than one view exists)
┌ content ─────────────────────────────┬ panel ──────────────┐
│ conversation, form or metrics        │ SidePanelSection ×n │
└──────────────────────────────────────┴─────────────────────┘
```

- The panel is context, not a dump: requester and properties open, the rest collapsed with a one-line summary on the closed section.
- **Ticket detail (M4-05)** is the reference: conversation first; messages told apart by edge, icon and label (customer, public reply, internal note), never by a tinted surface under text; a composer whose Public/Internal switch, edge and send-button wording all name the audience; panel sections Requester, Details, Assignment, SLA, Attachments, Duplicates, Dates.
- Below 1280 px the panel is a drawer from the right, opened by "Ticket context" in the actions row (M4-13, [responsive](responsive.md)).
- **Every record page is framed by `RecordLayout`** (M4-10; `components/shared/record-layout.tsx`): identity header (what the record is, its name, one line of detail), state badges and actions, then the page's own content. The open section is `?tab=` in the URL (`DetailTabs`, and the ticket's activity tabs).
- **Historical view.** With `?as_of=<instant>` (set by "Show this state" or "See the record just before this change" in History, or by a link) the page *is* the record as it was: a banner that stays in view names the instant ("You are looking at this ticket as it was on …"), the header drops every action and every current-state badge, the identity line repeats the instant, and the content is the History section (differences table, every recorded field with the differing ones marked by an edge, a tint and "(now: …)", then the timeline). No form, composer or side panel of the live record is rendered, so nothing can be edited from the past. "Back to now" is one click and returns to the History section. An `as_of` that is not an instant is ignored.

## Settings page

The 17 routes under `/settings`.

```text
PageHeader            section title · what this section controls
form                  fields grouped by subject, one column, labels above
  save bar            appears when dirty: Save · Discard, with the unsaved-changes guard
danger zone           destructive actions, separated, each behind ConfirmDialog
```

- One save state per page: idle, dirty, saving, saved, failed. Never a toast alone for a failure the user must act on.
- Descriptions sit under the label, not as placeholder text.
- **As built (M4-12).** Every page under `/settings` is framed by `SettingsPage` (`components/shared/settings-page.tsx`): h2 title, one line on what the section controls, the primary action on the right ("Add webhook"), content, and an optional `danger` zone. Destructive actions in settings today are per row (revoke, delete, remove a holiday) and each sits behind `ConfirmDialog`; no section has a page-level destructive action yet, so the zone is unused. A card embedded in another page (SLA defaults, duplicate detection) is a bordered section with an h3.
- **Save bar and unsaved changes.** Form pages (General, Branding, Email sender, Tickets, Automation duplicates) end with `SaveBar`: Save is disabled until something changed; while dirty it says "Unsaved changes", offers Discard and stays in view at the bottom. Dirty means "differs from what is saved" (`!isDefaultValue`), so undoing an edit makes the form clean again. `UnsavedChangesGuard` asks before an in-app navigation away from a dirty form (stay, or leave and discard) and lets the browser warn on reload or close; the inline SLA policy and calendar editors use it too.
- **Purpose-built reading.** SLA policies are cards that say what they promise: targets per priority in working time ("30 min", "4 h", never days), who they apply to, the calendar they count on, when they warn, the version. Calendars show the working week as a row per day with its windows in words, a 24-hour bar, the weekly total, and holidays as dates ("20 Oct 2026 · Dashain"). The target inputs say their value in working time as you type.

## Dashboard

```text
PageHeader            workspace name                                   period control
Right now             open · unassigned · SLA due soon · SLA breached   (KpiTile, each a link to the queue view)
This period           8 tiles from GET /v1/dashboard                    (KpiTile, each a link to its report; sparklines from trend_series)
Trends                created vs resolved · backlog · SLA compliance
Breakdown             channel · priority · team · age · agent workload · time in status
```

As built (M4-08): "Right now" is owned by the tickets feature and composed in by the route; its counts are the ticket list's own totals for the view each tile opens.

- Three levels of weight, not one wall of equal cards. A tile is the link to its report; no repeated "open the full report" line ([ux-review G3](ux-review.md#g3--information-hierarchy-is-flat)).
- A sparkline appears on a tile only where the series already exists in the API response.

## Report page

All 28 catalogue reports share one layout, so a filter behaves the same everywhere.

```text
PageHeader            report title · description        end: print, export CSV/XLSX
ParameterBar          period · comparison · group-by · filters      chips: parameters in force
totals                KPI row; with the comparison on, the change against the previous period in words (the API returns previous totals, not a series)
chart                 one chart per definition, legend, axes in the workspace format
table                 the same numbers, drill-down on each count
```

- Chart and table complement each other: the chart shows the pattern, the table the exact values; the accessible table equivalent is always one click away ([data-visualization](data-visualization.md)).
- Drill-down keeps the clicked measure, so "3 Resolved" lists three records.

## Pre-authentication pages

Sign-in, workspace entry and finder, password reset, invitation and the platform sign-in (M5-03) share `AuthLayout`:

| Slot | Content |
|---|---|
| Header | `BrandMark` linking to the landing site; `ThemeToggle compact` (labels read out, shown from `sm`) |
| Eyebrow | the `WorkspaceChip` when the page belongs to a workspace (never a read-only field) |
| Heading | one `h1` (`text-3xl`), one sentence under it |
| Form | fields in reading order; the primary button full width; secondary links after the field they relate to, so tab order matches the screen |
| Done state | `SentPanel` (a status with its own `h2`) naming what was sent where, plus "use a different …" and a way back |
| Brand panel | from `lg` only, an `aside` after the form: headline, three points, a decorative example ticket (`aria-hidden`); nothing in it is needed to complete the page |

Rules: no workspace, account or address is ever confirmed or denied before sign-in (the finder and the reset request always answer the same way); the page works at 360 px without sideways scrolling; storage is a convenience (recent workspaces), never required.

## Checklist for a new page

1. Pick the pattern and reuse its components; if none fits, add the pattern here before writing the second page that needs it.
2. Put every control that changes what is shown in the `FilterBar` (state in the URL) and every context block in a `SidePanelSection`.
3. Implement the five states with their own copy.
4. Keep view state in the URL.
5. Check the keyboard path top to bottom, then run the axe scan in both themes.
