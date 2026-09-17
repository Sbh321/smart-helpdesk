# Spacing and layout

## Base scale

Tailwind v4's `--spacing` multiplier is `0.25rem` (4px) in comfortable density and `0.22rem` (≈3.5px) in compact density ([tokens.md](tokens.md)). Utilities (`p-2`, `gap-4`, `mt-6`) therefore rescale automatically.

| Step | Comfortable | Compact | Typical use |
|---|---|---|---|
| 0.5 | 2px | 1.8px | hairline gaps between badges |
| 1 | 4px | 3.5px | icon/text gap inside badges |
| 2 | 8px | 7px | inline gaps, chip padding |
| 3 | 12px | 10.5px | control horizontal padding, list item padding |
| 4 | 16px | 14px | card padding (dense), form field gap |
| 6 | 24px | 21px | card padding (default), section gap |
| 8 | 32px | 28px | page section gap |
| 12 | 48px | 42px | empty-state vertical padding |

Only these steps are used in components; arbitrary values (`p-[13px]`) are rejected in review.

## Layout grid

```text
┌────────────────────────────────────────────────────────────────────┐
│ topbar: height var(--topbar-height) (56px), sticky, z-sticky        │
├──────────────┬─────────────────────────────────────────────────────┤
│ sidebar      │ content area                                         │
│ 256px        │   padding-inline: 24px (≥1280px) / 16px (<1280px)   │
│ (collapsed   │   max-width: var(--content-max-width) 1440px,       │
│  56px)       │   centred; tables may opt out to full width          │
│              │                                                      │
└──────────────┴─────────────────────────────────────────────────────┘
```

| Token / rule | Value |
|---|---|
| `--sidebar-width` | 16rem (256px); collapsed 3.5rem (56px, icon rail); collapsed state persisted in `localStorage['sh.sidebar']` |
| `--topbar-height` | 3.5rem (56px) |
| `--content-max-width` | 90rem (1440px); dashboards and settings are centred; the ticket table and ticket detail use full width |
| Gutters | `px-6` at ≥ 1280px, `px-4` below |
| Breakpoints used | `md` 768px (tablet: sidebar becomes a sheet), `lg` 1024px, `xl` 1280px (default target), `2xl` 1536px |
| Vertical rhythm | page: `PageHeader` then `space-y-6` sections; cards inside sections `gap-4` in a `grid` |

Ticket detail is a two-column layout at ≥ 1024px: main column (`minmax(0, 1fr)`) with description, comments and composer; side column `w-80` (320px) with status/priority/assignment/SLA panels and the explanation panels. Below 1024px the side column stacks above the comments as collapsible sections.

## Density mapping

| Element | Comfortable | Compact |
|---|---|---|
| Control height sm / md / lg | 32 / 36 / 40px | 28 / 32 / 36px |
| Table row height | 44px | 36px |
| Table header height | 40px | 32px |
| Sidebar item height | 36px | 32px |
| Card padding | 24px | 20px |
| Form field vertical gap | 16px | 12px |
| Badge padding | 2px 8px | 2px 6px |
| Timeline entry gap | 16px | 12px |

Text sizes do not change with density.

## Component padding conventions

| Component | Padding | Gap |
|---|---|---|
| Button | `px-3` (sm `px-2.5`, lg `px-4`), height from `--control-*` | icon-text `gap-2` |
| Input / Select / Combobox | `px-3`, height `--control-md` | |
| Card | `p-6` (`p-4` when the card contains only a table or list) | header/body `gap-4` |
| Dialog | `p-6`, max width 32rem (form) / 48rem (wide), `gap-4` between header, body, footer | |
| Sheet (side panel) | `p-6`, width 28rem | |
| Popover / dropdown | `p-1` container, items `px-2 py-1.5` | |
| Table cell | `px-3`, first cell `ps-4`, last cell `pe-4`; vertical padding derived from row height | |
| Badge | see density table; `rounded-badge` | |
| Toast | `p-4`, width 22rem | |
| Empty state | `py-12 px-6`, centred, max width 28rem | `gap-3` |
| Page header | `pb-4` with a `border-b`; title and actions `justify-between`, actions `gap-2` | |
| Form | fields `space-y-4`; two-column groups `grid grid-cols-2 gap-4` at ≥ md | |

## Table row heights

Rows are fixed height (`--table-row-height`) so scrolling, keyboard navigation (`j`/`k`) and skeleton loaders align exactly; cell content that would exceed one line is truncated per [typography.md](typography.md). Expandable rows are not used in the MVP; details open in the ticket page or a side sheet.

## Icon sizing

`--icon-sm` (16px) inside controls and table cells, `--icon-md` (20px) in the sidebar and page header actions, `--icon-lg` (24px) in empty states and KPI tiles. The `lucide-react` `IconContext` provider sets `size` and `strokeWidth={1.75}` once at the app root.
