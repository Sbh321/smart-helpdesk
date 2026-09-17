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

## Primitive inventory (shadcn on Base UI)

| shadcn component | Base UI primitive | Used for |
|---|---|---|
| `button`, `toggle-group` | `useRender`, `Toggle`, `ToggleGroup` | actions, density/theme segmented controls |
| `input`, `textarea`, `label`, `field` | `Input`, `Field`, `Fieldset` | forms with TanStack Form |
| `select` | `Select` | short enumerations (status, priority, tier) |
| `combobox` | `Combobox` / `Autocomplete` | **all searchable pickers**: agent, contact, organisation, category, tags (multi), team |
| `checkbox`, `radio-group`, `switch` | `Checkbox`, `Radio`, `Switch` | settings, row selection |
| `dialog`, `alert-dialog`, `sheet` | `Dialog`, `AlertDialog` | forms, confirmations, side panels |
| `popover`, `tooltip`, `dropdown-menu`, `menubar` | `Popover`, `Tooltip`, `Menu`, `Menubar` | explanations, row actions |
| `tabs`, `card`, `badge`, `separator`, `skeleton`, `scroll-area`, `avatar`, `progress` | `Tabs`, `Separator`, `ScrollArea`, `Avatar`, `Progress` | layout and feedback |
| `table`, `pagination` | plain elements | `DataTable` base |
| `sonner` | (sonner) | toasts |
| `command` | (cmdk) | **only** `CommandPalette` |
| `calendar`, `date-picker` | (react-day-picker 10) + `Popover` | date-range filters |
| `sidebar`, `breadcrumb`, `alert`, `empty`, `kbd`, `chart` | assorted | shell, banners, empty states, shortcut hints, Recharts wrapper |

Rule: if a picker needs search, use `combobox`; never build `Popover + Command`. `cmdk` is quarantined in one file so it can be replaced by Base UI `Combobox` without touching features.

## Shared compositions

Each composition lives in `src/components/shared/<Name>/` and ships with loading, empty and error states where it renders data. Props are sketches; TypeScript types are the source of truth.

### DataTable

```ts
interface DataTableProps<TRow> {
  columns: ColumnDef<TRow>[];              // TanStack Table v9
  data: TRow[] | undefined;
  rowCount: number | undefined;
  state: { page: number; pageSize: 25 | 50 | 100; sort: string | undefined; selected: string[] };
  onStateChange(next: Partial<DataTableProps<TRow>['state']>): void;   // writes route search params
  isLoading: boolean; isError: boolean; error?: ApiError; onRetry(): void;
  getRowId(row: TRow): string;
  onRowOpen?(row: TRow): void;              // Enter / click
  bulkActions?: BulkAction[];               // rendered in the selection bar
  columnVisibilityKey: string;             // localStorage key
  emptyState: ReactNode;
}
```

Server mode only (`manualPagination`, `manualSorting`, `manualFiltering`); sticky header; fixed row height; sortable headers with `aria-sort`; row selection checkbox column; `j`/`k`/`Enter`/`x` keyboard navigation; skeleton rows while loading with `keepPreviousData`; `ErrorState` inline; footer with page size select and `Pagination`.

### PageHeader

`{ title, description?, breadcrumbs?, actions?: ReactNode, tabs?: TabDef[] }` — renders `h1`, optional eyebrow, action buttons (`gap-2`), and route tabs.

### EmptyState / ErrorState / ForbiddenState

`{ icon, title, description, action?: { label, onClick | to } }`; `ErrorState` adds `error` (shows `title`/`detail`/request id) and `onRetry`. Used by every list and detail route via the route `errorComponent` and `notFoundComponent`.

### StatusBadge, PriorityBadge, SlaBadge

| Component | Props | Rendering |
|---|---|---|
| `StatusBadge` | `{ status: TicketStatus; size?: 'sm' \| 'md' }` | tinted badge with status icon (`Circle`, `UserCheck`, `Loader`, `Clock`, `CheckCircle`, `Archive`) and label |
| `PriorityBadge` | `{ level: 'P1'…'P4'; score?: number; explanation?: PriorityExplanation; overridden?: boolean }` | solid badge `P1 Critical`; with `explanation` it becomes a `Popover` trigger opening `ExplanationPanel`; overridden shows a pencil icon and "manual" tooltip |
| `SlaBadge` | `{ timer: SlaTimerSummary; now?: Date; variant: 'badge' \| 'countdown' }` | state colour + icon; countdown variant shows remaining/overdue duration updating every 30 s, announced through `aria-live="polite"` only on state change, not every tick |

### ExplanationPanel

`{ kind: 'priority' | 'assignment' | 'duplicate'; data: Explanation }` — renders the factor table (`factor`, `raw`, `normalized`, `weight`, `contribution`) with a horizontal contribution bar per row, the settings version, and for assignment the ranked shortlist with exclusion reasons; for duplicates the per-channel breakdown. This is the academic demonstration surface and is reused in the settings live preview.

### CommandPalette

⌘K / Ctrl+K; groups: Navigate (routes), Tickets (recent, search by `#number` or text through `/v1/tickets?search=`), Actions (new ticket, toggle theme, toggle density). Built on shadcn `command` inside a `Dialog`; the only cmdk import.

### ConfirmDialog

`{ title, description, confirmLabel, destructive?, onConfirm: () => Promise<void> }` on `AlertDialog`; focus lands on Cancel for destructive actions; busy state disables both buttons.

### FilterBar

`{ filters: FilterDef[]; value: FilterState; onChange }` — chips for active filters, `Combobox` multi-selects (status, priority, team, agent, category, tag, organisation), `DatePicker` range, SLA state select, free-text search input (debounced 300 ms, `/` focuses it); state is the route search params. "Clear all" and a saved-view placeholder (V1).

### Timeline

`{ events: TicketEvent[] }` — vertical list of history entries (status, priority, assignment, SLA warning/breach/met, comment markers) with actor avatar, relative time, and old → new values; SLA and priority entries link to their explanation.

### CommentComposer

Tabs `Public reply` / `Internal note` (internal styled with `--warning` eyebrow), Markdown textarea, attachment drop zone (`AttachmentUploader`), submit with `Ctrl+Enter`, optional "Set status after sending" select.

### AttachmentUploader

`{ ticketId; max: 10; onChange(ids: string[]) }` — drag-and-drop + file input; per-file progress via `XMLHttpRequest` PUT to the presigned URL; states: queued, uploading (progress), verifying (complete call), ready, failed (retry); rejected types shown before upload ([storage](../03-architecture/storage.md)).

### NotificationBell

Unread count badge (from session, polled/realtime), `Popover` list of the latest 10 notifications with mark-read, "View all" route; new notifications announced in an `aria-live="polite"` region as "n new notifications".

### ThemeToggle / DensityToggle

`ToggleGroup` with three/two options; described in [themes.md](themes.md).

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
