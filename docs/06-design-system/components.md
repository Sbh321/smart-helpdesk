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

## Primitive inventory (shadcn on Base UI)

| shadcn component | Base UI primitive | Used for | Status |
|---|---|---|---|
| `button`, `toggle-group` | `useRender`, `Toggle`, `ToggleGroup` | actions, density/theme segmented controls | `button` installed (M1-11); `toggle-group` not yet |
| `input`, `textarea`, `label`, `field` | `Input`, `Field`, `Fieldset` | forms with TanStack Form | `input`, `label`, `field` installed (M1-12); `textarea` and `input-group` installed (M1-14) as dependencies of `combobox` |
| `select` | `Select` | short enumerations (status, priority, tier) | installed (M1-14): the DataTable page-size selector; `SelectField`, `SelectFilter` (M1-15) |
| `combobox` | `Combobox` / `Autocomplete` | **all searchable pickers**: agent, contact, organisation, category, tags (multi), team | installed (M1-14): `MultiSelectFilter`; `EntityCombobox` and `TagInput` (M1-15) |
| `checkbox`, `radio-group`, `switch` | `Checkbox`, `Radio`, `Switch` | settings, row selection | `checkbox` installed (M1-14): DataTable row selection; the others not yet |
| `dialog`, `alert-dialog`, `sheet` | `Dialog`, `AlertDialog` | forms, confirmations, side panels | `dialog` installed (M1-12); the others not yet |
| `popover`, `tooltip`, `dropdown-menu`, `menubar` | `Popover`, `Tooltip`, `Menu`, `Menubar` | explanations, row actions | `popover`, `dropdown-menu` installed (M1-12); `tooltip`, `menubar` not yet |
| `tabs`, `card`, `badge`, `separator`, `skeleton`, `scroll-area`, `avatar`, `progress` | `Tabs`, `Separator`, `ScrollArea`, `Avatar`, `Progress` | layout and feedback | `card`, `badge`, `separator`, `skeleton`, `avatar` installed (M1-12); `tabs`, `scroll-area`, `progress` not yet |
| `table`, `pagination` | plain elements | `DataTable` base | `table` installed (M1-14); `pagination` **not** installed: it is a list of page-number links, and a server-mode table with `meta.total` needs first/previous/next/last buttons and a range, which `DataTablePagination` draws with `Button` |
| `sonner` | (sonner) | toasts | installed (M1-12); rewritten to read our `ThemeProvider` instead of `next-themes` |
| `command` | (cmdk) | **only** `CommandPalette` | not yet: the M1-12 palette is a `Dialog` with a navigation list; `cmdk` waits for ticket search, which needs the ticket list (M2-10) |
| `calendar`, `date-picker` | (react-day-picker 10) + `Popover` | date-range filters | `calendar` installed (M1-14, `react-day-picker` 10.0.1): `DateRangeFilter`; `date-picker` is a docs recipe, not a component, and `DateRangeFilter` is that recipe |
| `sidebar`, `breadcrumb`, `alert`, `empty`, `kbd`, `chart` | assorted | shell, banners, empty states, shortcut hints, Recharts wrapper | `breadcrumb`, `alert`, `empty`, `kbd` installed (M1-12); `chart` not yet (M3-01). `sidebar` is **not** installed: the shell's navigation is a plain `nav` + list (about forty lines), and the shadcn `sidebar` block brings collapsible rails, a provider and cookie persistence the MVP does not use |

Rule: if a picker needs search, use `combobox`; never build `Popover + Command`. `cmdk` is quarantined in one file so it can be replaced by Base UI `Combobox` without touching features.

Two Base UI constraints found while wiring the shell, both easy to trip over again: `Menu.GroupLabel` throws unless it is inside a `Menu.Group` (so `DropdownMenuLabel` is always wrapped), and a menu is for commands — the notification list is a `Popover`, not a `DropdownMenu`.

## Shared compositions

Each composition is one kebab-case file in `src/components/shared/` (`empty-state.tsx`, not `EmptyState/index.tsx`) and ships with loading, empty and error states where it renders data. Shell pieces live in `src/components/layout/`. Props are sketches; TypeScript types are the source of truth.

| Composition | File | Status |
|---|---|---|
| `PageHeader` | `shared/page-header.tsx` | built (M1-12); `tabs` variant not yet |
| `EmptyState` | `shared/empty-state.tsx` | built (M1-12) |
| `ErrorState` | `shared/error-state.tsx` | built (M1-12) |
| `ForbiddenState` | `shared/forbidden-state.tsx` | built (M1-12) |
| `NotFoundState` | `shared/not-found-state.tsx` | built (M1-12) |
| `TextField` | `shared/text-field.tsx` | built (M1-12); labelled input with `aria-describedby`/`aria-invalid` wiring, used by every auth form |
| `SkipLink` | `shared/skip-link.tsx` | built (M1-12); owns `MAIN_CONTENT_ID` |
| `ThemeToggle` | `shared/theme-toggle.tsx` | built (M1-11) |
| `AppShell`, `Sidebar`, `Topbar`, `Breadcrumbs` | `layout/` | built (M1-12) |
| `AuthLayout` | `layout/auth-layout.tsx` | built (M1-12); the card frame of the pre-authentication pages |
| `NotificationBell` | `layout/notification-bell.tsx` | placeholder (M1-12): count from the session, list in M2-16 |
| `CommandPalette` | `layout/command-palette.tsx` | shell (M1-12): ⌘K/Ctrl+K opens a dialog listing the permitted routes; search and actions with the ticket list (M2-10) |
| `DataTable`, `FilterBar`, `SearchFilter`, `MultiSelectFilter`, `DateRangeFilter`, `SelectFilter` | `shared/data-table/` | built (M1-14; `SelectFilter` M1-15); a folder rather than one file, because the table, its footer, its column menu, its keyboard hook, its storage helper and the filter primitives are eleven files behind one `index.ts` |
| `FormField`, `SelectField`, `TextareaField` | `shared/form-field.tsx`, `select-field.tsx`, `textarea-field.tsx` | built (M1-15): the label/description/error frame of `TextField` for any control, and the two controls the forms needed |
| `EntityCombobox` | `shared/entity-combobox.tsx` | built (M1-15): searchable single picker fed by the API (organisation in the contact form, contact in the ticket dialog) |
| `TagInput` | `shared/tag-input.tsx` | built (M1-15): chips in a multiple `Combobox`, suggestions from `GET /v1/tags?search=`, unknown names offered as "Add …" |
| `FormErrorBanner` | `shared/form-error-banner.tsx` | built (M1-15): a failed save that is not about one field (403, 409, 5xx, offline), with the request id |
| `BackLink` | `shared/back-link.tsx` | built (M1-15): "← Back to …" in the `PageHeader` eyebrow of detail pages |
| `StatusBadge`, `PriorityBadge` | `shared/status-badge.tsx`, `priority-badge.tsx` | built (M1-17) without the explanation popover and the "manual" marker (M2) |
| `SlaBadge`, `ExplanationPanel`, `Timeline`, `CommentComposer`, `AttachmentUploader`, `ConfirmDialog`, `KpiTile`, chart wrappers | — | not yet (M2, M3) |
| `DensityToggle` | — | not yet |

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
}
```

- **Server mode only.** `manualPagination`, `manualSorting`, `rowCount`; no client row models are registered. Filtering is not a table feature: filters are not per column, they live in the URL next to sort and page, so there is no `manualFiltering` and no column-filter state. Sort, page and page size come in as props and leave through `onStateChange`; nothing about the list's query is local state.
- **Columns** come from `dataTableColumnHelper<TRow>()`; `meta: { label, className? }` gives the column menu and the sort button their text. Sorting is opt-in (`enableSorting: true`) and the column id must be a field on the endpoint's sort allow-list. `enableHiding: false` keeps a column out of the column menu.
- **Sort** cycles ascending → descending → the endpoint's default; sortable headers carry `aria-sort` (`ascending`/`descending`/`none`) and a `button` named after the column; plain headers carry none. `aria-sort` follows the primary field of a two-field sort (tickets default to `-priority_score,-created_at`, so Priority reads "descending"). When "back to the default" would look like no change — the default already sorts that column in the direction just left — the cycle takes the other direction instead, which is why the table takes `defaultSort` (M1-17).
- **Column visibility** is a per-browser preference in `localStorage` (`sh.table.<id>.columns`); every storage access is in `try/catch` and a failure reads as "no preference".
- **Selection** exists when `bulkActions` is given: a checkbox column (select-all is `indeterminate` when part of the page is selected) and a `section` labelled "Actions for the selected rows" above the table showing the count, the caller's actions and "Clear selection". Selection covers the loaded page only.
- **Keyboard:** one row is in the tab order (roving `tabindex`); ↑/↓ or `k`/`j` move, Home/End jump, Enter opens (`onRowOpen`), `x`/Space toggles selection. Keys pressed inside a control in the row (the checkbox) belong to the control. With `onRowOpen`, the table is described by a visually hidden hint.
- **States:** skeleton rows plus a `status` message and `aria-busy` on first load; the previous page stays visible, dimmed and `aria-busy`, while the next one loads (`placeholderData: keepPreviousData` in the query); `ErrorState` with retry and request id when the fetch fails; the caller's `emptyState` when the list is empty; "This page is empty / Go to the first page" when a page number beyond the end came from the URL.
- **Layout:** a scroll container (`max-h-[min(70dvh,48rem)]`) with a sticky header; the footer (`DataTablePagination`) shows the page-size `Select` (25/50/100), "26–50 of 1,387", "Page 2 of 56" and first/previous/next/last buttons inside a `nav` named "Pagination".
- **React Compiler:** the component opts out with `'use no memo'`. TanStack Table's row and header methods read table state the compiler cannot see, so memoised rows would show stale selection or visibility. The controlled slices passed to `useTable` (`sorting`, `pagination`) are memoised by hand: `useTable` publishes a changed slice back into its store, and a new array on every render is a render loop.

`useListParams(schema)` (`lib/list-params/`) is the other half: `defineListSchema({ sortFields, defaultSort, filters })` describes an endpoint, its `searchSchema` is the route's `validateSearch`, and the hook returns `params` (validated, defaults filled in), `apiQuery` (the object for openapi-fetch, `filter[key]` keys and comma lists), `activeFilterCount`, `setPage`, `setPerPage`, `setSort`, `setSearch`, `setFilter`, `clearFilters` and `update(patch)`. Filters are `multiFilter(item)` (comma list, OR), `dateRangeFilter()` (`YYYY-MM-DD,YYYY-MM-DD`) and, since M1-15, `choiceFilter(values)` (exactly one value, for `filter[archived]=true|all`; absent means the API's default). Sorts are one field, or two comma-separated fields when that is the schema's default or the schema sets `multiSort: true` (M1-17). Every change is a router navigation, so reload, a shared link and back/forward all restore the list; any change except the page itself resets `page` to 1; default values are left out of the URL; search params that belong to someone else are kept. Invalid values — an unknown sort field, `per_page=7`, a malformed date range, an item that fails the filter's Zod schema — fall back to the default instead of failing the route.

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

Forms (TanStack Form + Zod) validate on submit and, after a failed submit, again on every change (`revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' })` with `validators.onDynamic`). A plain `onSubmit` validator is not enough: TanStack Form refuses the next submit while a field still carries the previous submit's error, so a corrected field was never re-checked. 422 problem details go through `serverFieldErrors()` (`lib/forms/messages.ts`, which folds `tags.0` into `tags`) and are merged with the client messages per field; anything else shows a `FormErrorBanner`.

Three local changes to the generated `ui/combobox.tsx`, all accessible names axe asked for: `ComboboxChip` takes `removeLabel` for its icon-only remove button, and `ComboboxInput` takes `triggerLabel` and `clearLabel` for its open and clear buttons (the open button is also taken out of the tab order: the input already opens the list).

### StatusBadge, PriorityBadge, SlaBadge

| Component | Props | Rendering |
|---|---|---|
| `StatusBadge` | `{ status: TicketStatus; size?: 'sm' \| 'md' }` | tinted badge with status icon (`Circle`, `UserCheck`, `Loader`, `Clock`, `CheckCircle`, `Archive`) and label |
| `PriorityBadge` | `{ level: 'P1'…'P4'; score?: number; explanation?: PriorityExplanation; overridden?: boolean }` | solid badge `P1 Critical`; with `explanation` it becomes a `Popover` trigger opening `ExplanationPanel`; overridden shows a pencil icon and "manual" tooltip |
| `SlaBadge` | `{ timer: SlaTimerSummary; now?: Date; variant: 'badge' \| 'countdown' }` | state colour + icon; countdown variant shows remaining/overdue duration updating every 30 s, announced through `aria-live="polite"` only on state change, not every tick |

As built (M1-17): `StatusBadge` `{ status }` and `PriorityBadge` `{ level }` on the `--status-*` / `--priority-*` tokens, text always written out (`Open`, `P1 Critical`), unknown values rendered as plain text. `score`, `explanation` and `overridden` wait for the ticket page (M2).

### ExplanationPanel

`{ kind: 'priority' | 'assignment' | 'duplicate'; data: Explanation }` — renders the factor table (`factor`, `raw`, `normalized`, `weight`, `contribution`) with a horizontal contribution bar per row, the settings version, and for assignment the ranked shortlist with exclusion reasons; for duplicates the per-channel breakdown. This is the academic demonstration surface and is reused in the settings live preview.

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

Not built: chips for active filters (the trigger's count badge carries it), the SLA-state select (with SLA timers in M2) and saved views (V1). `DateRangeFilter` is used by the ticket list's "Created" filter since M1-17.

### Timeline

`{ events: TicketEvent[] }` — vertical list of history entries (status, priority, assignment, SLA warning/breach/met, comment markers) with actor avatar, relative time, and old → new values; SLA and priority entries link to their explanation.

### CommentComposer

Tabs `Public reply` / `Internal note` (internal styled with `--warning` eyebrow), Markdown textarea, attachment drop zone (`AttachmentUploader`), submit with `Ctrl+Enter`, optional "Set status after sending" select.

### AttachmentUploader

`{ ticketId; max: 10; onChange(ids: string[]) }` — drag-and-drop + file input; per-file progress via `XMLHttpRequest` PUT to the presigned URL; states: queued, uploading (progress), verifying (complete call), ready, failed (retry); rejected types shown before upload ([storage](../03-architecture/storage.md)).

### NotificationBell

Unread count badge (from session, polled/realtime), `Popover` list of the latest 10 notifications with mark-read, "View all" route; new notifications announced in an `aria-live="polite"` region as "n new notifications".

As built (M1-12): the badge and the `aria-live` region are real and read `unread_notifications` from the session; the popover body says where the list is coming from (M2-16) instead of showing an empty inbox.

### ThemeToggle / DensityToggle

`ThemeToggle` is a `<fieldset>` of three `Button`s with `aria-pressed` (Light / Dark / System); described in [themes.md](themes.md). `DensityToggle` (two options) is not built yet.

### KpiTile

`{ label, value, delta?, trend?: number[], intent?: 'neutral' | 'success' | 'warning' | 'destructive' }` — dashboard tile with `text-3xl tabular-nums` value and a tiny sparkline (Recharts `LineChart` without axes).

### Chart compositions

`TicketsOverTimeChart`, `TicketsByStatusChart`, `TicketsByPriorityChart`, `TicketsByCategoryChart`, `AgentWorkloadChart`, `SlaComplianceChart` — thin wrappers over shadcn `ChartContainer` with a `ChartConfig` mapping keys to semantic tokens, each with a visually hidden `<table>` of the same data for screen readers.

## Feature-owned components

Feature folders own screens and forms (`TicketForm`, `TicketDetail`, `AssignmentDialog`, `SlaPolicyForm`, `AutomationSettingsForm`, `WebhookForm`, `RoleEditor`), composed from the shared set above. They never import primitives for anything the shared set covers.

## States checklist (Definition of Done)

Every route/composition renders: loading (skeleton matching final layout), empty (with a primary action), error (with retry and request id), forbidden (no data), and, for mutations, pending/disabled + success toast + error mapping to fields. Reviewed via the dev-only `/dev/components` gallery route that renders each composition in each state.

## Distribution (V1)

The tokens, `tokens.css`, and the shared compositions will be published as a private shadcn registry (`registry.json` + `shadcn build`) so the customer portal and any admin tool install the same system with `shadcn add @smart-helpdesk/data-table`. Not built in the MVP.
