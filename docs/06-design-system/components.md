# Components

One primitive layer (Base UI through shadcn/ui, [ADR-0002](../adr/0002-ui-primitive-strategy.md)); our compositions live in `src/components/shared` and are the only place feature code composes primitives into helpdesk-specific widgets. Layout in [03-architecture/frontend.md](../03-architecture/frontend.md).

## Installation

```bash
pnpm dlx shadcn@latest init            # accepts Base UI (default), CSS variables on
pnpm dlx shadcn@latest add \
  button input textarea label field select combobox checkbox radio-group switch \
  dialog alert-dialog sheet popover tooltip dropdown-menu menubar \
  tabs card badge separator skeleton scroll-area avatar progress \
  table pagination sonner command calendar date-picker \
  sidebar breadcrumb alert chart empty kbd toggle-group
```

`components.json` pins `"base": "base"` and `"tailwind": { "cssVariables": true }`. Optional shadcn dependencies not installed: `vaul`, `embla-carousel`, `input-otp`, `react-resizable-panels`.

Primitives are added when the task that needs them lands, not all at once, so `src/components/ui/` only ever holds code that is in use. The **Status** column below is the inventory; it is updated by the task that adds a component.

The primary Button keeps its opaque semantic background on hover. A translucent fill reduced white-label contrast below WCAG AA; the rendered Settings forms are covered by the browser axe scan.

## Primitive inventory (shadcn on Base UI)

| shadcn component | Base UI primitive | Used for | Status |
|---|---|---|---|
| `button`, `toggle-group` | `useRender`, `Toggle`, `ToggleGroup` | actions, density/theme segmented controls | `button` installed (M1-11); `toggle-group` not yet |
| `input`, `textarea`, `label`, `field` | `Input`, `Field`, `Fieldset` | forms with TanStack Form | `input`, `label`, `field` installed (M1-12); `textarea` and `input-group` installed (M1-14) as dependencies of `combobox` |
| `select` | `Select` | short enumerations (status, priority, tier) | installed (M1-14): the DataTable page-size selector; `SelectField`, `SelectFilter` (M1-15) |
| `combobox` | `Combobox` / `Autocomplete` | **all searchable pickers**: agent, contact, organisation, category, tags (multi), team | installed (M1-14): `MultiSelectFilter`; `EntityCombobox` and `TagInput` (M1-15) |
| `checkbox`, `radio-group`, `switch` | `Checkbox`, `Radio`, `Switch` | settings, row selection | `checkbox` (M1-14) and `switch` installed; `radio-group` not installed: the composer's Public/Internal switch and the Appearance menu use native radios and `DropdownMenuRadioGroup` |
| `dialog`, `alert-dialog`, `sheet` | `Dialog`, `AlertDialog` | forms, confirmations, side panels | `dialog` (M1-12), `alert-dialog` (`ConfirmDialog`), `sheet` (M4-13, on Base UI `Dialog`: the ticket context below 1280 px) installed |
| `popover`, `tooltip`, `dropdown-menu`, `menubar` | `Popover`, `Tooltip`, `Menu`, `Menubar` | explanations, row actions | `popover`, `dropdown-menu` installed (M1-12), `tooltip` (M4-02; one `TooltipProvider` at the root, and every tooltip is `Hint` from `components/ui/tooltip.tsx`: `<Hint label=…>` around its one trigger, never the native `title` attribute; an icon-only control keeps its `aria-label`); `menubar` not yet |
| `tabs`, `card`, `badge`, `separator`, `skeleton`, `scroll-area`, `avatar`, `progress` | `Tabs`, `Separator`, `ScrollArea`, `Avatar`, `Progress` | layout and feedback | `card`, `badge`, `separator`, `skeleton`, `avatar` installed (M1-12); `tabs` added on Base UI (M2-06); `scroll-area`, `progress` not yet |
| `table`, `pagination` | plain elements | `DataTable` base | `table` installed (M1-14); `pagination` **not** installed: it is a list of page-number links, and a server-mode table with `meta.total` needs first/previous/next/last buttons and a range, which `DataTablePagination` draws with `Button` |
| `sonner` | (sonner) | toasts | installed (M1-12); rewritten to read our `ThemeProvider` instead of `next-themes` |
| `command` | (cmdk) | **only** `CommandPalette` | not yet: the M1-12 palette is a `Dialog` with a navigation list; `cmdk` waits for ticket search, which needs the ticket list (M2-10) |
| `calendar`, `date-picker` | (react-day-picker 10) + `Popover` | date-range filters | `calendar` installed (M1-14, `react-day-picker` 10.0.1): `DateRangeFilter`; `date-picker` is a docs recipe, not a component, and `DateRangeFilter` is that recipe |
| `sidebar`, `breadcrumb`, `alert`, `empty`, `kbd`, `chart` | assorted | shell, banners, empty states, shortcut hints, Recharts wrapper | `breadcrumb`, `alert`, `empty`, `kbd` installed (M1-12); `chart` installed (M3-01, Recharts 3.10; its dark selector changed from `.dark` to `[data-theme="dark"]`, though every chart colour is a CSS variable anyway). `sidebar` is **not** installed: the shell's navigation is a plain `nav` + list (about forty lines), and the shadcn `sidebar` block brings collapsible rails, a provider and cookie persistence the MVP does not use |

Rule: if a picker needs search, use `combobox`; never build `Popover + Command`. `cmdk` is quarantined in one file so it can be replaced by Base UI `Combobox` without touching features.

Two Base UI constraints found while wiring the shell, both easy to trip over again: `Menu.GroupLabel` throws unless it is inside a `Menu.Group` (so `DropdownMenuLabel` is always wrapped), and a menu is for commands — the notification list is a `Popover`, not a `DropdownMenu`.

## Shared compositions

Each composition is one kebab-case file in `src/components/shared/` (`empty-state.tsx`, not `EmptyState/index.tsx`) and ships with loading, empty and error states where it renders data. Shell pieces live in `src/components/layout/`. Props are sketches; TypeScript types are the source of truth.

| Composition | File | Purpose |
|---|---|---|
| `PageHeader`, `BackLink` | `shared/page-header.tsx`, `back-link.tsx` | page title, description, eyebrow and actions |
| `RecordLayout`, `DetailTabs`, `SidePanelSection` | `shared/record-layout.tsx`, `detail-tabs.tsx`, `side-panel-section.tsx` | the record page frame, its URL-backed sections and the collapsible context panel; historical view with `?as_of=` (M4-05, M4-10) |
| `SettingsPage`, `SaveBar`, `UnsavedChangesGuard` | `shared/settings-page.tsx`, `save-bar.tsx` | the settings frame, the save state of a form and the leave guard (M4-12) |
| `FilterChips` | `data-table/filter-chips.tsx` | the filters in force as removable chips under a `FilterBar` (M4-04; the report toolbar since M4-09) |
| `DataTable`, `FilterBar`, `SearchFilter`, `MultiSelectFilter`, `DateRangeFilter`, `SelectFilter` | `shared/data-table/` | server- or client-side tables and their filters (M1-14, M1-15) |
| `EmptyState`, `ErrorState`, `ForbiddenState`, `NotFoundState` | `shared/*-state.tsx` | the non-data states ([§States](#states)) |
| `TextField`, `FormField`, `SelectField`, `TextareaField`, `TimeZoneField`, `EntityCombobox`, `TagInput`, `FormErrorBanner` | `shared/` | form fields with label, description and error wiring; a banner for failures that are not about one field |
| `ConfirmDialog` | `shared/confirm-dialog.tsx` | the question before an irreversible action |
| `StatusBadge`, `PriorityBadge`, `SlaIndicator`, `PriorityExplanation` | `shared/` | ticket state, priority, SLA timer (M4-07) and the priority explanation (M4-06) |
| `KpiTile`, `ChartCard`, `SeriesChart`, `Sparkline` | `shared/kpi-tile.tsx`, `chart-card.tsx`, `charts/` | key figures and charts ([data visualisation](data-visualization.md)) |
| `CodeBlock` | `shared/code-block.tsx` | machine text to read and copy (webhook payloads, M4-11) |
| `Lightbox`, `useFileDrop`, `DropOverlay` | `shared/lightbox.tsx`, `file-drop.tsx` | looking at files, and dropping files on a target ([§Lightbox](#lightbox), [§File drop](#file-drop)) |
| `AppearanceMenu`, `ThemeToggle` | `shared/appearance-menu.tsx`, `theme-toggle.tsx` | theme and density in the account menu (M4-02) |
| `SkipLink` | `shared/skip-link.tsx` | owns `MAIN_CONTENT_ID` |
| `AppShell`, `Sidebar`, `Topbar`, `Breadcrumbs`, `CommandPalette`, `ConnectionIndicator`, `AuthLayout` | `layout/` | the shell; the sidebar is an icon rail below 1280 px (M4-13) |
| `NotificationBell` | `features/notifications/components/notification-bell.tsx` | reaches the shell through the `Topbar` `notifications` slot |

Composition is checked by the lint gate: features may not import a vendor directly or another feature's internals (Biome `noRestrictedImports`, M4-01), and `scripts/check-design-tokens.ts` rejects literal colours, radii and layers.

### DataTable

`shared/data-table/data-table.tsx`, on TanStack Table **v9** (`@tanstack/react-table` 9.2.4: `useTable` + `tableFeatures`; the v8 fallback was not needed). As built (M1-14):

```ts
interface DataTableProps<TRow, TSort extends string> {
  id: string;                                   // column visibility key: localStorage `sh.table.<id>.columns`
  label: string;                                // the table's caption (accessible name)
  columns: DataTableColumns<TRow>;              // dataTableColumnHelper<TRow>().columns([...])
  data: TRow[] | undefined;                     // undefined until the first page arrived → skeleton rows
  rowCount: number | undefined;                 // meta.total
  state: { page: number; per_page: 25 | 50 | 100; sort: TSort | `-${TSort}` };  // = useListParams().params
  onStateChange(patch: { page?; per_page?; sort?: … | undefined }): void;      // = useListParams().update
  defaultSort?: string;                         // = schema.defaultSort (M1-17), see "Sort" below
  getRowId(row: TRow): string;
  getRowLabel?(row: TRow): string;              // "Select Arjun Rai" on the row checkbox
  onRowOpen?(row: TRow): void;                  // Enter on a focused row, or a click on it
  isFetching?: boolean; error?: unknown; onRetry?(): void;
  emptyState: ReactNode;                        // the caller knows "empty" from "no matches"
  toolbar?: ReactNode;                          // usually a FilterBar
  bulkActions?(selection: { ids: string[]; clear(): void }): ReactNode;  // also switches the selection column on
  selection?: { ids: readonly string[]; onChange(ids: string[]): void };  // controlled, kept across pages (M2-11)
  defaultColumnVisibility?: Record<string, boolean>;                      // e.g. { sla_due_at: false } (M2-11)
}
```

- **Server mode only.** `manualPagination`, `manualSorting`, `rowCount`; no client row models are registered. Filtering is not a table feature: filters are not per column, they live in the URL next to sort and page, so there is no `manualFiltering` and no column-filter state. Sort, page and page size come in as props and leave through `onStateChange`; nothing about the list's query is local state.
- **Columns** come from `dataTableColumnHelper<TRow>()`; `meta: { label, className? }` gives the column menu and the sort button their text. Sorting is opt-in (`enableSorting: true`) and the column id must be a field on the endpoint's sort allow-list. `enableHiding: false` keeps a column out of the column menu.
- **Sort** cycles ascending → descending → the endpoint's default; sortable headers carry `aria-sort` (`ascending`/`descending`/`none`) and a `button` named after the column; plain headers carry none. `aria-sort` follows the primary field of a two-field sort (tickets default to `-priority_score,-created_at`, so Priority reads "descending"). When "back to the default" would look like no change — the default already sorts that column in the direction just left — the cycle takes the other direction instead, which is why the table takes `defaultSort` (M1-17).
- **Column visibility** is a per-browser preference in `localStorage` (`sh.table.<id>.columns`); every storage access is in `try/catch` and a failure reads as "no preference". `defaultColumnVisibility` hides columns the viewer has not toggled yet.
- **Selection** exists when `bulkActions` is given: a checkbox column (select-all is `indeterminate` when part of the page is selected) and a `section` labelled "Actions for the selected rows" above the table showing the count, the caller's actions and "Clear selection". Without `selection` it covers the loaded page only; with it (M2-11, the ticket list) the parent owns the ids, rows of other pages stay selected, select-all adds or removes the page's rows only, and `bulkActions` receives every selected id.
- **Keyboard:** one row is in the tab order (roving `tabindex`); ↑/↓ or `k`/`j` move, Home/End jump, Enter opens (`onRowOpen`), `x`/Space toggles selection. Keys pressed inside a control in the row (the checkbox) belong to the control. With `onRowOpen`, the table is described by a visually hidden hint.
- **States:** skeleton rows plus a `status` message and `aria-busy` on first load; the previous page stays visible, dimmed and `aria-busy`, while the next one loads (`placeholderData: keepPreviousData` in the query); `ErrorState` with retry and request id when the fetch fails; the caller's `emptyState` when the list is empty; "This page is empty / Go to the first page" when a page number beyond the end came from the URL.
- **Layout:** a scroll container (`max-h-[min(70dvh,48rem)]`) with a sticky header; the footer (`DataTablePagination`) shows the page-size `Select` (25/50/100), "26–50 of 1,387", "Page 2 of 56" and first/previous/next/last buttons inside a `nav` named "Pagination".
- **React Compiler:** the component opts out with `'use no memo'`. TanStack Table's row and header methods read table state the compiler cannot see, so memoised rows would show stale selection or visibility. The controlled slices passed to `useTable` (`sorting`, `pagination`) are memoised by hand: `useTable` publishes a changed slice back into its store, and a new array on every render is a render loop.

`useListParams(schema)` (`lib/list-params/`) is the other half: `defineListSchema({ sortFields, defaultSort, filters })` describes an endpoint, its `searchSchema` is the route's `validateSearch`, and the hook returns `params` (validated, defaults filled in), `apiQuery` (the object for openapi-fetch, `filter[key]` keys and comma lists), `activeFilterCount`, `setPage`, `setPerPage`, `setSort`, `setSearch`, `setFilter`, `clearFilters` and `update(patch)`. Filters are `multiFilter(item)` (comma list, OR), `dateRangeFilter()` (`YYYY-MM-DD,YYYY-MM-DD`) and, since M1-15, `choiceFilter(values)` (exactly one value, for `filter[archived]=true|all`; absent means the API's default). Sorts are one field, or two comma-separated fields when that is the schema's default or the schema sets `multiSort: true` (M1-17). Every change is a router navigation, so reload, a shared link and back/forward all restore the list; any change except the page itself resets `page` to 1; default values are left out of the URL; search params that belong to someone else are kept. Invalid values — an unknown sort field, `per_page=7`, a malformed date range, an item that fails the filter's Zod schema — fall back to the default instead of failing the route.

### SlaIndicator

`components/shared/sla-indicator.tsx` (M4-04 as `SlaHealth`, generalised in M4-07). One SLA timer in one line, the same component in the ticket queue, the ticket header and the SLA panel. Props: `{ state, dueAt, timeZone, now?, kind?: 'first_response' | 'resolution', compact?, className? }`.

| State | Icon | Word | Time part |
|---|---|---|---|
| `running` | clock | On track | "3 hours left" |
| `warning` | triangle | Due soon | "20 minutes left" |
| `breached` | octagon | Breached | "2 hours overdue" |
| `paused` | pause bars | Paused | none: a countdown would imply the clock runs |
| `met` | tick | Met | none |
| `cancelled` / no timer | — | "—" | — |

Every state has its own icon shape, colour and word, so they stay apart in greyscale (tested). The full form reads "Due soon · 20 minutes left"; `compact` (the queue) shows only the time and keeps the word for screen readers; `kind` prefixes "Response" or "Resolution". The exact due time is in the tooltip. `slaRemaining()` is the pure time-in-words rule. The same state words label the queue's SLA filter.

The ticket feature composes it twice (`features/sla`): `TicketSlaSummary` in the header lists the current cycle's timers whose clock matters (running, due soon, breached, paused), each labelled; `TicketSlaPanel` shows each timer kind with its indicator, the moment that matters (due, paused since, met), a pending hint on a paused timer, and a "Policy and calendar" disclosure with the policy name and the version the timer started with, the Business calendar (or 24×7), the target in working time, the warning time, paused time so far and the strategy. A polite live region holds only the states, so a screen reader hears a change once and never the 30-second tick.

### FilterChips

`components/shared/data-table/filter-chips.tsx` (M4-04). The filters in force, each removable, rendered by `FilterBar` under its controls. A list can carry ten filters behind menus, so without the chips the only sign that a view is filtered is a count.

### AppearanceMenu

`components/shared/appearance-menu.tsx`. Theme and density as two radio groups inside the account menu (M4-02). Replaces the three-button theme control that used to sit in the top bar, and gives density its first control although the tokens have supported it since milestone 1. Changes are announced politely; the menu stays open while choosing. The login and platform layouts, which have no account menu, keep `ThemeToggle`.

### SidePanelSection

`components/shared/side-panel-section.tsx`. One collapsible block of a record page's context panel, built on `details`/`summary`: open and close work with the keyboard and are announced without ARIA of our own. `summary` shows a one-line value while closed, `defaultOpen` opens on first render, `static` renders an always-open section with no control. At most two sections open by default (progressive disclosure, principle D5).

### PageHeader

`{ title, description?, eyebrow?: ReactNode, actions?: ReactNode }` — renders the page's single `h1`, an optional eyebrow slot and right-aligned actions (`gap-2`). As built (M1-12) the breadcrumb trail lives in the `Topbar`, not in the header, so `breadcrumbs` is the `eyebrow` slot; `tabs` arrives with the first tabbed route.

### EmptyState / ErrorState / ForbiddenState / NotFoundState

`{ icon, title, description, action?: ReactNode }` on shadcn `empty`; the heading inside is an `h2`, because the page's `h1` belongs to `PageHeader`. `ErrorState` adds `error: unknown` (an `ApiError` shows its `title`, its human-readable `detail` and the request id; a network failure gets its own wording) and `onRetry`. `ForbiddenState` and `NotFoundState` are `EmptyState` with fixed copy; `NotFoundState` takes the way back as an `action`, because only the route knows where back is. Used by every route through `errorComponent` and `notFoundComponent`.

### TextField

`{ id, label, value, onValueChange, description?, errors?: string[] }` plus the native input props — the one place that wires a label, a description and error messages to an input. Errors set `aria-invalid` and are referenced from `aria-describedby` as `<id>-error`; the message element carries `role="alert"`, so a message that appears after a failed submit is announced. Client-side (Zod) and server-side (422 problem details) messages are merged by field name before they reach it.

### FormField, SelectField, TextareaField, EntityCombobox, TagInput

As built (M1-15). `FormField` is `TextField`'s frame for any control: `{ id, label, description?, errors?, children(control) }` renders the label bound to `id`, the description and the error list (`role="alert"`), and hands the control `{ id, aria-invalid, aria-describedby }`. The controls built on it:

| Composition | Props | Notes |
|---|---|---|
| `SelectField` | `{ id, label, value, onValueChange, options: { value, label }[], placeholder?, description?, errors? }` | Base UI `Select`; the trigger is the labelled control |
| `TextareaField` | `TextField`'s props on a `Textarea` | |
| `EntityCombobox` | `{ id, label, value: { value, label, detail? } \| null, onChange, options, onQueryChange, isLoading?, placeholder?, description?, errors? }` | Base UI `Combobox` with the input as the control and `filter={null}`: matches come from the API for the (debounced, by the caller) query; the selected record stays listed; clear button when set |
| `TagInput` | `{ id, label, value: string[], onChange, suggestions: string[], onQueryChange, description?, errors?, placeholder? }` | chips in a `multiple` `Combobox`; a typed name matching no suggestion is offered as "Add …"; names compare case-insensitively; the API creates unknown names on save, so nothing is created while typing |
| `TimeZoneField` | `{ id, label, value, onValueChange, onBlur?, description?, errors? }` | Base UI `Combobox` over the zones the API accepts (`lib/datetime/time-zone-ids.ts`, generated from PHP's `DateTimeZone::listIdentifiers()`), `UTC` first, each with its current offset (`UTC+05:45`); words match in any order against the name and offset; only a listed zone can be chosen. `browserTimeZone()` maps the old names Chromium reports (`Asia/Katmandu`, `Asia/Calcutta`) to the current ones for defaults. Used by sign-up, Settings → General, business calendars and the console's workspace forms |

Forms (TanStack Form + Zod) validate on submit and, after a failed submit, again on every change (`revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' })` with `validators.onDynamic`). A plain `onSubmit` validator is not enough: TanStack Form refuses the next submit while a field still carries the previous submit's error, so a corrected field was never re-checked. 422 problem details go through `serverFieldErrors()` (`lib/forms/messages.ts`, which folds `tags.0` into `tags`) and are merged with the client messages per field; anything else shows a `FormErrorBanner`.

Three local changes to the generated `ui/combobox.tsx`, all accessible names axe asked for: `ComboboxChip` takes `removeLabel` for its icon-only remove button, and `ComboboxInput` takes `triggerLabel` and `clearLabel` for its open and clear buttons (the open button is also taken out of the tab order: the input already opens the list).

### StatusBadge, PriorityBadge

`StatusBadge { status }` and `PriorityBadge { level }` on the `--status-*` / `--priority-*` tokens: an icon plus the word (`Open`, `P1 Critical`), never colour alone; an unknown value is shown as plain text. The SLA timer has its own component, [`SlaIndicator`](#slaindicator); the manual-override marker sits beside the badge in the ticket header, and the explanation is `PriorityExplanation` ([§Explanations](#explanations)).

### Explanations

There is no generic `ExplanationPanel` (M4-06): each decision has its own small component, because the three strategy outputs share nothing but "strategy + version". All three follow one pattern: a sentence first, a visual second, the raw numbers behind a `<details>` last, and never a recomputation.

| Decision | Component | Shows |
|---|---|---|
| Priority | `PriorityExplanation` (shared) | sentence naming the deciding factors; one bar per factor (`aria-hidden`, the points beside it are the accessible value); "Show the calculation" with the factor table and strategy/version; the manual override reason when set |
| Assignment | `AssignmentReason` (automation feature) | "Why this Agent" sentence (automatic, by hand with or without the strategy's agreement, override with the broken rule, no eligible Agent, unassigned); decision time; "Show the ranking" with the ranked Agents, the exclusions and strategy/version |
| Duplicate | `TicketDuplicatesPanel` (tickets feature) | candidate number, title and status; similarity; shared words as tokens; "Found by {strategy} · {version}" or "Marked by hand" |

### CommandPalette

⌘K / Ctrl+K; groups: Navigate (routes), Tickets (recent, search by `#number` or text through `/v1/tickets?search=`), Actions (new ticket, toggle theme, toggle density). Built on shadcn `command` inside a `Dialog`; the only cmdk import.

As built (M1-12) it is the shell of that: a `Dialog` opened by ⌘K/Ctrl+K or by the topbar button, listing the navigation targets the session's permissions allow. `cmdk` is not installed until the Tickets and Actions groups need it (ticket list, M2-10), so the quarantine rule has nothing to quarantine yet.

### ConfirmDialog

`{ title, description, confirmLabel, destructive?, onConfirm: () => Promise<void> }` on `AlertDialog`; focus lands on Cancel for destructive actions; busy state disables both buttons.

### FilterBar

`{ filters: FilterDef[]; value: FilterState; onChange }` — chips for active filters, `Combobox` multi-selects (status, priority, team, agent, category, tag, organisation), `DatePicker` range, SLA state select, free-text search input (debounced 300 ms, `/` focuses it); state is the route search params. "Clear all" and a saved-view placeholder (V1).

As built (M1-14) it is a container plus three primitives, composed by the page rather than configured from a `FilterDef[]`, all in `shared/data-table/`:

| Primitive | Props | Behaviour |
|---|---|---|
| `FilterBar` | `{ children, activeCount, onClear }` | a `fieldset` with a visually hidden "Filters" legend; "Clear filters" shows while `activeCount > 0` and clears every filter and the search, keeping sort and page size |
| `SearchFilter` | `{ value, onChange, label, placeholder?, debounceMs = 300, shortcut = true }` | `type="search"` input; typing is local and reaches the URL when typing pauses (or on Enter); a change of `value` from outside (back button, "Clear filters") replaces the text and cancels a pending write; `/` focuses it from anywhere outside a text field |
| `MultiSelectFilter` | `{ label, options: { value, label }[], value: string[], onChange, isLoading? }` | Base UI `Combobox` (`multiple`) with the search input inside the popup; the trigger (`role="combobox"`) shows the label and a count badge; the typed query survives a pick so several matches can be picked in a row; selected values that are not among the options stay listed so they can be removed |
| `DateRangeFilter` | `{ label, value: { from, to } \| undefined, onChange }` | `Popover` + shadcn `calendar` (react-day-picker 10) in range mode; the first click picks one end, the second the other; values are inclusive `YYYY-MM-DD` calendar days (the API's `filter[created_between]` form); "Clear dates" in the popover footer |
| `SelectFilter` | `{ label, options, value, defaultValue, onChange }` | one-of-several `Select` (M1-15, the contact list's "Hide / Only / Include archived"); choosing the default option removes the filter from the URL |

Since M4-04 the filters in force also appear as removable `FilterChips` under the bar, and the ticket list has an SLA filter; saved views stay V1. `DateRangeFilter` is used by the ticket list's "Created" filter since M1-17.

### Record values (shared vocabulary)

`lib/format/record-values.ts` (M4-03). One implementation of "what does this stored value say": `formatRecordValue(value, timeZone, {attribute, names, structured})` and `readableChanges(changes, …)`, plus `attributeLabel` and `shortId`. Rules in order — nothing → "Empty", booleans → Yes/No, lists and objects either joined (`structured: 'join'`, audit detail) or counted (`'count'`, timelines), `*_seconds` → duration, a known relation id → the record's name via `NameLookup`, a known enumeration → its label, an instant → the workspace zone, and only an unnameable id → its last eight characters. The ticket timeline, the entity History tab and the audit log all read through it; the names come from `useRecordNames` (exported by `@/features/reports`), which reuses option queries already in the cache.

### Ticket timeline

The Timeline tab of a ticket lists its history newest first with the actor and each change in words, through the shared record-value formatter (M4-03, [§Record values](#record-values-shared-vocabulary)): names instead of ids, labels instead of stored values. The History tab (all records) adds the recorded changes, audit entries and the historical view ([page patterns §Record page](page-patterns.md#record-page)).

### Comment composer

`features/tickets/components/comments-panel.tsx` (M4-05): the conversation and its composer. Messages are told apart by an edge, an icon and a label (customer, public reply, internal note), never by a tinted surface. The composer's Public reply / Internal note switch, its edge and the send button ("Send reply to the Contact" / "Save internal note") all name the audience; without `comments.internal` the switch is absent. Markdown is rendered by a restricted renderer (`safe-comment-markdown.tsx`) that never parses HTML. `r` focuses the composer.

### AttachmentUploader

`features/media/components/attachment-uploader.tsx`: the one upload field. A drop zone takes files dragged onto it; "Choose files" opens the device's chooser; with `library`, "Choose from library" opens `MediaPickerDialog`. Files go straight to object storage with a presigned PUT, with a state per file (uploading with progress, ready, failed with retry) and the type and size checked before upload ([storage](../03-architecture/storage.md)). Each file is a tile with a thumbnail (the device's copy through an object URL until it is stored) that opens the `Lightbox` over all tiles. A form reads `onChange` and library files join its tiles; a screen that acts at once (the Ticket's Attachments section, the library) passes `onUploaded` and `onPicked`. It lays itself out by its own width (container queries), so it fits a form and the side panel alike. The comment composer, ticket create, the Ticket's Attachments section and the library use it; the workspace logo field has the same three routes (drop, choose, library) for one image.

### Lightbox

`components/shared/lightbox.tsx`: the one place a file is looked at, opened from every thumbnail, tile, chip and library card. A full-screen dialog on a near-black scrim (`--scrim`) in both themes shows the name and a summary (type, size, dimensions), and a toolbar: zoom out, the zoom level (fit), zoom in, rotate, details, "Open in a new tab" and download. Images zoom with the buttons, the wheel, a double-click and + − 0, pan by dragging, and load the original instead of the preview rendition once zoomed in; PDF and text show in a frame on white (`--paper`); any other type gets a file card with download and open. With more than one file: previous and next buttons, ← → Home End, a horizontal swipe, a thumbnail strip, and an announcement of "n of m: name". R rotates and I toggles the details panel. The keys listen on the window while it is open, so a button that becomes disabled (Next on the last file) never strands them. It takes `LightboxItem`s with ready-made URLs and labels; `features/media` builds them (`mediaLightboxItem`, `localFileLightboxItem`).

### MediaBrowser and MediaPickerDialog

`features/media/components/media-browser.tsx`: the library's body, shared by the library page (list state in the URL, `useListParams`) and the picker (component state, `useLocalListParams`): folder tree, search, type, tag and trash filters, sort, a grid of cards (`media-grid.tsx`, thumbnail or type icon, name, type and size, an actions menu) or the table, pagination, selection and the lightbox over the page. `media-picker-dialog.tsx` puts the whole browser in a large dialog with a footer ("n selected", Use); `single` and `type="image"` make it a logo picker, and files already attached show but cannot be picked again.

### File drop

`components/shared/file-drop.tsx`: `useFileDrop(onFiles, disabled)` gives any element drag-and-drop of files (only drags that carry files count; nested children do not flicker the state), and `DropOverlay` is the "drop here" layer over a large target (the library's contents, which upload into the open folder).

### NotificationBell

Unread count badge (from session, polled/realtime), `Popover` list of the latest 10 notifications with mark-read, "View all" route; new notifications announced in an `aria-live="polite"` region as "n new notifications".

As built (M2-09): the count is polled every 30 s (`meta.total` of the unread list, the session value until the first answer); the popover lists the latest ten, unread first, each with its ticket link (opening marks it read) and a "Mark as read" button named after the notification; "Mark all as read" and "View all notifications" close it. Only a rise after the first reading is announced ("n new notifications"), so signing in does not read out the backlog. It lives in the notifications feature and reaches the shell through a slot, because `components/` may not import features.

### AppearanceMenu / ThemeToggle

Theme (Light, Dark, System) and density (Comfortable, Compact) are two radio groups in the account menu (`AppearanceMenu`, M4-02), stored per browser ([themes](themes.md)). `ThemeToggle`, a `<fieldset>` of three `Button`s with `aria-pressed`, remains for the pages without the account menu (sign-in).

### KpiTile

`{ label, value, change?: { direction: 'up' | 'down' | 'flat', text } | null, noChange?, footer?, headingLevel? }` — a `section` named by its heading, the value in `text-3xl tabular-nums`, the change against the previous period **in words** ("Up 12% from 40", "Up 2.5 pp from 90%") with a decorative arrow, and an optional footer link. As built (M3-01) there is no sparkline and no intent colour: whether "up" is good depends on the measure (resolved vs breaches), so colour would mislead. The value arrives formatted (`lib/format/measure.ts`: counts, durations as "3h 20m", percentages, ratios, bytes).

M4-08: `compact` (tighter padding, `text-2xl` value) for dashboard rows, and `sparkline` (a `Sparkline` from `components/shared/charts/sparkline.tsx`, plain SVG, `aria-hidden`, gaps for missing points) beside the value so tiles keep one height. A tile that is itself a link is wrapped in the router's `Link` with `kpiTileLinkClassName` (focus ring on the tile edge, border lifts on hover) instead of a footer link. Sparklines appear only where the API names the series (`trend_series`).

### ChartCard and the report charts

`ChartCard { title, description?, actions?, children, table, showTableLabel, hideTableLabel }` (shared) frames a chart with its accessible table: the table is always rendered, visually hidden (`sr-only`) until the toggle (`aria-expanded`) shows it, and printed. `SeriesChart` is shared (`components/shared/charts/`, moved there in M4-01): line, area, bar (horizontal across categories, upright over time, grouped), stacked bar and histogram over shadcn `ChartContainer`; `--chart-1..6` in series order, `accessibilityLayer`, values formatted by unit, a legend on every chart in series order, never two value axes ([data visualisation](data-visualization.md), M4-09). Feature-owned in `features/reports/components/`: `HeatmapChart` (weekday × hour CSS grid, one hue mixed into the surface with `color-mix`, `role="img"`), `ChartTable` (the plain table). The six named compositions planned here (`TicketsOverTimeChart`, …) were not built: the dashboard's six series come from the API with their report and measures, so one generic `SeriesChart` draws them all. Status and priority bars use the series colours, not the badge tints: `--priority-p4` and the status surfaces are below 3:1 on the surface.

## Feature-owned components

Feature folders own screens and forms (`TicketForm`, `TicketDetail`, `AssignmentDialog`, `SlaPolicyForm`, `AutomationSettingsForm`, `WebhookForm`, `RoleEditor`), composed from the shared set above. They never import primitives for anything the shared set covers.

## States

Every asynchronous view has five states, each in its own words (M4-13):

| State | Shown as | Example |
|---|---|---|
| Loading | a skeleton of the real layout (rows, tiles, cards) with a screen-reader status; never a bare "Loading…" line or spinner for a view | `RowsSkeleton` in the directory settings |
| Refreshing | the previous data stays, the container carries `aria-busy` | ticket queue, dashboard tiles |
| Empty | "nothing here yet", with the first action when there is one | "No tickets yet", "No entries yet." |
| No results | "nothing matches", naming the filter or search, with a way to clear it | "No tickets match these filters", "Nothing matches “zzzz”" + Clear search |
| Error / forbidden | `ErrorState` with the reason and Retry; `ForbiddenState`, or the section is not offered at all | |

Spinners are for actions in progress (an export being prepared, a reconnecting socket), not for views. Forms validate on submit first and then as each field changes (`revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' })` everywhere, including the sign-in forms since M4-13); a field shows an error before it was submitted only when the server said so.

## States checklist (Definition of Done)

Every route/composition renders: loading (skeleton matching final layout), empty (with a primary action), error (with retry and request id), forbidden (no data), and, for mutations, pending/disabled + success toast + error mapping to fields. Checked by the browser tests of each feature (there is no component gallery: Storybook was dropped in favour of the redesign, see the M4 decision in [ux-review](ux-review.md)).

## Distribution (V1)

The tokens, `tokens.css`, and the shared compositions will be published as a private shadcn registry (`registry.json` + `shadcn build`) so the customer portal and any admin tool install the same system with `shadcn add @smart-helpdesk/data-table`. Not built in the MVP.
