# Typography

Text is the bulk of a helpdesk screen; the scale is deliberately small and the defaults favour density and legibility over expression.

## Font families

| Token | Value | Use |
|---|---|---|
| `--font-sans` | `ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji"` | everything by default |
| `--font-mono` | `ui-monospace, "SFMono-Regular", Menlo, Consolas, "Liberation Mono", monospace` | ticket numbers, IDs, error codes, API keys, code in comments |

Decision: **system stack by default; Inter optional.** The system stack needs no network request (on-prem and air-gapped installs, CSP without font origins) and renders instantly. If Inter is wanted, it is self-hosted: `@fontsource-variable/inter` copied into `public/fonts/` and declared with `font-display: swap`; `--font-sans` then becomes `"Inter Variable", <system stack>`. Google Fonts is never loaded at runtime. Inter is not enabled in the MVP.

`font-feature-settings: "cv11", "ss01"` and `font-variant-numeric: tabular-nums` are applied on tables, badges and countdowns so digits align.

## Size and line-height scale

Tokens are defined in the `@theme inline` block ([tokens.md](tokens.md)) and generate `text-*` utilities that set both size and line height.

| Token | Size | Line height | Use |
|---|---|---|---|
| `text-xs` | 12px | 16px | badges, table meta, timestamps, helper text |
| `text-sm` | 14px | 20px | **body default**: table cells, forms, comments, nav |
| `text-base` | 16px | 24px | long-form reading (ticket description), dialog body |
| `text-lg` | 18px | 28px | card titles, section headings (h3) |
| `text-xl` | 20px | 28px | page subsection headings (h2) |
| `text-2xl` | 24px | 32px | page titles (h1) |
| `text-3xl` | 30px | 36px | KPI tile numbers only |

The root font size is 16px; the app body uses `text-sm` (14px) because agents scan dense tables. Ticket description and comments render at `text-base` inside a `max-w-prose` (65ch) container.

## Weights

| Token | Weight | Use |
|---|---|---|
| `font-regular` | 400 | body |
| `font-medium` | 500 | labels, table headers, nav items, button text |
| `font-semibold` | 600 | headings, KPI numbers, badge text |
| `font-bold` | 700 | not used in the UI (reserved for emphasis inside rich text) |

## Letter spacing

`tracking-tight` (−0.01em) on `text-2xl`/`text-3xl` headings and KPI numbers; `tracking-wide` (0.02em) with `uppercase text-xs font-medium` for eyebrow labels (section labels in the sidebar, "INTERNAL NOTE" markers); `tracking-normal` elsewhere.

## Heading scale

| Element | Classes | Where |
|---|---|---|
| Page title (`h1`) | `text-2xl font-semibold tracking-tight` | `PageHeader` |
| Section (`h2`) | `text-xl font-semibold` | settings sections, dashboard groups |
| Card / panel title (`h3`) | `text-lg font-semibold` or `text-base font-semibold` inside dense panels | cards, explanation panels |
| Subheading (`h4`) | `text-sm font-medium text-muted-foreground uppercase tracking-wide` | eyebrow labels |

Heading levels follow document order (one `h1` per route; no skipped levels), enforced by the axe rule `heading-order`.

## Body scale

| Context | Classes |
|---|---|
| Table cell | `text-sm` (numbers `tabular-nums`, right-aligned) |
| Form label | `text-sm font-medium` |
| Helper / error text | `text-xs text-muted-foreground` / `text-xs text-destructive` |
| Ticket description, comment body | `text-base leading-relaxed max-w-prose` rendered through the sanitised Markdown renderer |
| Timeline entries | `text-sm`, actor in `font-medium`, time in `text-xs text-muted-foreground` |
| Toast | `text-sm` |
| Badge | `text-xs font-semibold uppercase tracking-wide` (status, priority, SLA) |

## Monospace usage

`font-mono text-sm` for: ticket numbers in tables (`#1042`), UUIDs in developer settings, webhook payload previews (`<pre>` with horizontal scroll), API client ids, error codes highlighted inside ticket text by the duplicate detector's code tokeniser (`ERR-401` rendered as `<code>`).

## Numbers and dates

- `tabular-nums` on every numeric column, KPI tile and countdown so widths do not jitter as values change.
- Dates through `lib/datetime`: relative ("3 h ago") with the absolute value in a `title` and in a tooltip; absolute in the agent's display timezone with the zone abbreviation when it differs from the tenant timezone ([frontend architecture](../03-architecture/frontend.md)).
- Durations for SLA as `1h 20m` (never `01:20:00`), remaining time turning to `-` prefix when breached.

## Truncation rules

| Content | Rule |
|---|---|
| Ticket title in tables | single line, `truncate`, full text in `title` attribute and on hover card |
| Contact/organisation names | `truncate` at cell width; never below 12ch |
| Description preview | `line-clamp-2` |
| Email addresses | `truncate` with `direction: ltr` preserved |
| IDs | never truncated; monospace with copy button |
| Badges | never truncated; abbreviate instead (`P1`, `IN PROG` is not allowed: use `In progress` on a wider badge or icon-only with tooltip at narrow widths) |

## Rich text

Ticket descriptions and comments accept a Markdown subset (paragraphs, emphasis, lists, links, inline code, fenced code). Rendering goes through a sanitiser allow-list; links get `rel="noopener noreferrer"` and open in a new tab; images are not rendered inline in the MVP (attachments panel instead). Typography for rendered Markdown is the same scale as above via a small `.prose-helpdesk` ruleset (no `@tailwindcss/typography` dependency).
