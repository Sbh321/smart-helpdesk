# Design tokens

Tokens are CSS custom properties in `frontend/src/styles/tokens.css`, consumed by Tailwind v4 through `@theme`, `@theme inline` and `@theme reference` (see §tokens.css). Colour, shadow, radius and easing utilities resolve to `var(--x)` at runtime, so they re-resolve under `[data-theme="dark"]`, `[data-density="compact"]` and a future `[data-tenant]` scope. Decision: [ADR-0002](../adr/0002-ui-primitive-strategy.md).

## Three layers

```mermaid
flowchart LR
    P[Primitive palette\n--neutral-50..950, --blue-500, --red-600 ...\noklch, theme-independent] --> S[Semantic tokens\n--background, --primary, --sla-breached ...\ndefined per theme on :root / [data-theme=dark]]
    S --> C[Component tokens\n--table-row-height, --sidebar-width, --badge-radius\nonly where one component owns a value]
    S --> U[Tailwind utilities via @theme inline\nbg-primary, text-sla-breached, rounded-control]
```

| Layer | Where defined | Who reads it | Theme-dependent |
|---|---|---|---|
| Primitive | `:root` only | semantic layer only | no |
| Semantic | `:root` (light) and `[data-theme="dark"]`; shadcn aliases on `:root, [data-theme]` | components, utilities, charts | yes |
| Component | `:root, [data-density="comfortable"]` and `[data-density="compact"]` | one component | sometimes (density) |

Naming: kebab-case, `--<concept>` for the surface and `--<concept>-foreground` for text on it (shadcn convention); helpdesk families use a prefix: `--status-<status>`, `--priority-p1..p4`, `--sla-<state>`, `--chart-1..6`. Tailwind namespaces used: `--color-*`, `--font-*`, `--text-*`, `--font-weight-*`, `--leading-*`, `--tracking-*`, `--spacing`, `--radius-*`, `--shadow-*`, `--ease-*`, `--animate-*`.

## Semantic token catalogue

### Colour

| Token | Meaning | Utilities |
|---|---|---|
| `--background` / `--foreground` | page canvas and default text | `bg-background`, `text-foreground` |
| `--surface` / `--surface-foreground` | cards, panels, table body | `bg-surface` |
| `--surface-elevated` / `--surface-elevated-foreground` | popovers, dialogs, dropdowns, command palette | `bg-surface-elevated` |
| `--muted` / `--muted-foreground` | subdued backgrounds, secondary text, skeletons | `bg-muted`, `text-muted-foreground` |
| `--primary` / `--primary-foreground` | primary actions, active nav, links; **tenant-brandable** | `bg-primary` |
| `--secondary` / `--secondary-foreground` | secondary buttons, chips | `bg-secondary` |
| `--accent` / `--accent-foreground` | hover/selected rows, menu item hover | `bg-accent` |
| `--destructive` / `--destructive-foreground` | delete, breach, errors | `bg-destructive`, `text-destructive` |
| `--warning` / `--warning-foreground` | caution banners, SLA warning | `text-warning` |
| `--success` / `--success-foreground` | resolved, met SLA, success toasts | `text-success` |
| `--info` / `--info-foreground` | informational banners | `text-info` |
| `--border` | default 1px borders and dividers | `border-border` |
| `--input` | form control borders | `border-input` |
| `--ring` | focus ring colour | `ring-ring` |
| `--overlay` | scrim behind dialogs/sheets (colour with alpha) | `bg-overlay` |
| `--chart-1` … `--chart-6` | categorical chart series (order: primary, teal, amber, violet, rose, slate) | `fill-chart-1` via shadcn `ChartConfig` |
| `--status-open`, `--status-assigned`, `--status-in-progress`, `--status-pending`, `--status-resolved`, `--status-closed` | ticket status badges; each has `-foreground` | `bg-status-open` |
| `--priority-p1`, `--priority-p2`, `--priority-p3`, `--priority-p4` | priority badges; each has `-foreground` | `bg-priority-p1` |
| `--sla-ok`, `--sla-warning`, `--sla-breached`, `--sla-paused` | SLA badges and countdowns; each has `-foreground` | `text-sla-breached` |
| `--sidebar`, `--sidebar-foreground`, `--sidebar-accent`, `--sidebar-border` | shadcn sidebar block tokens | `bg-sidebar` |

Status and priority colours are always paired with an icon or text ([accessibility](accessibility.md)); colour is never the only signal.

### Typography, spacing, shape, elevation, motion, sizing

| Family | Tokens | Notes |
|---|---|---|
| Fonts | `--font-sans`, `--font-mono` | [typography](typography.md) |
| Sizes | `--text-xs` … `--text-3xl` with paired `--text-*--line-height` | Tailwind emits `text-xs` etc. |
| Weights | `--font-weight-regular 400`, `-medium 500`, `-semibold 600`, `-bold 700` | |
| Tracking | `--tracking-tight -0.01em`, `-normal 0`, `-wide 0.02em` | headings tight, uppercase labels wide |
| Spacing | `--spacing` (Tailwind v4 multiplier): 0.25rem comfortable, 0.22rem compact; `--density` 1 / 0.82 | [spacing](spacing.md) |
| Radius | `--radius` (base 0.5rem), `--radius-control`, `--radius-card`, `--radius-modal`, `--radius-badge`, `--radius-full` | control = radius − 2px, card = radius, modal = radius + 4px |
| Border width | `--border-width-hairline 1px`, `--border-width-strong 2px` | |
| Elevation | `--shadow-0 none`, `--shadow-1` (cards), `--shadow-2` (popovers), `--shadow-3` (dialogs), `--shadow-focus` | dark theme lowers alpha and adds a 1px border instead |
| Overlay | `--overlay` colour, `--overlay-blur 2px` | |
| Icon sizes | `--icon-xs 12px`, `--icon-sm 16px`, `--icon-md 20px`, `--icon-lg 24px` | `lucide-react` `size` reads these via `IconContext` |
| Control heights | `--control-sm`, `--control-md`, `--control-lg` | explicit values per density scope: 32/36/40px comfortable, 28/32/36px compact |
| Table | `--table-row-height`, `--table-header-height` | 44/40px comfortable, 36/32px compact |
| Density components | `--sidebar-item-height`, `--card-padding`, `--form-field-gap`, `--badge-padding-inline`, `--timeline-gap` | 36/24/16/8/16px comfortable, 32/20/12/6/12px compact ([spacing](spacing.md)) |
| Layout | `--sidebar-width 16rem`, `--sidebar-width-collapsed 3.5rem`, `--topbar-height 3.5rem`, `--content-max-width 90rem` | |
| Z-index | `--z-base 0`, `--z-sticky 10`, `--z-dropdown 20`, `--z-overlay 30`, `--z-modal 40`, `--z-toast 50`, `--z-tooltip 60` | Base UI portals get `--z-overlay`+ |
| Motion | `--duration-fast 120ms`, `-normal 200ms`, `-slow 320ms`; `--ease-standard cubic-bezier(.2,0,0,1)`, `--ease-enter cubic-bezier(0,0,.2,1)`, `--ease-exit cubic-bezier(.4,0,1,1)` | all zero under reduced motion |
| Opacity | `--opacity-disabled 0.5`, `--opacity-muted 0.7`, `--opacity-overlay 0.6` | |
| Focus | `--ring` colour, `--ring-width 2px`, `--ring-offset 2px` | `focus-visible:ring-[length:var(--ring-width)]` |

## tokens.css

`frontend/src/styles/tokens.css` is the source of truth. `frontend/src/styles/globals.css` imports `tailwindcss`, `tw-animate-css`, `shadcn/tailwind.css` and then `tokens.css`. The file has these sections, in this order:

| # | Section | Selector or at-rule | Contents |
|---|---|---|---|
| — | dark variant | `@custom-variant dark` | `dark:` targets `[data-theme="dark"]` and its descendants |
| 1 | Primitive palette | `:root` | the oklch values listed below |
| 2 | Semantic colour, light | `:root` | `color-scheme: light` and every semantic colour and shadow |
| 2b | Semantic colour, dark | `[data-theme="dark"]` | `color-scheme: dark` and the dark overrides |
| — | shadcn aliases | `:root, [data-theme]` | `--card`, `--popover`, `--sidebar-primary`, `--sidebar-ring`, `--shadow-focus` |
| 3 | Shape, elevation, motion, sizing | `:root` | theme-independent values |
| 4 | Density | `:root, [data-density="comfortable"]`, then `[data-density="compact"]` | spacing and component sizes |
| 5 | Reduced motion | `@media (prefers-reduced-motion: reduce)` | duration tokens set to `0ms` |
| 6 | Static Tailwind values | `@theme` | fonts, text sizes, weights, tracking |
| 7 | Runtime Tailwind utilities | `@theme inline` | `--color-*`, `--font-heading`, the shadcn radius scale, `--blur-overlay` |
| — | Same-name utilities | `@theme reference` | `--radius-control/card/modal/badge`, `--shadow-1..3`, `--ease-*` |

The shadcn aliases sit on `:root, [data-theme]` and not on `:root` alone. That way `var()` re-resolves inside a nested `[data-theme]` island.

```css
@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));

/* ---------- 1. Primitive palette (theme-independent; read by the semantic layer only) ---------- */
:root {
  --neutral-0:   oklch(1 0 0);
  --neutral-50:  oklch(0.985 0.002 250);
  --neutral-100: oklch(0.967 0.003 250);
  --neutral-200: oklch(0.922 0.004 250);
  --neutral-300: oklch(0.870 0.005 250);
  --neutral-400: oklch(0.708 0.008 250);
  --neutral-500: oklch(0.556 0.010 250);
  --neutral-600: oklch(0.446 0.012 250);
  --neutral-700: oklch(0.371 0.012 250);
  --neutral-800: oklch(0.269 0.012 250);
  --neutral-900: oklch(0.205 0.012 250);
  --neutral-950: oklch(0.145 0.010 250);

  --blue-400:   oklch(0.707 0.140 250);
  --blue-600:   oklch(0.546 0.190 258);
  --blue-700:   oklch(0.488 0.200 262);
  --teal-500:   oklch(0.700 0.120 190);
  --teal-700:   oklch(0.520 0.100 192);
  --amber-400:  oklch(0.828 0.160 84);
  --amber-600:  oklch(0.646 0.150 70);
  --amber-800:  oklch(0.470 0.120 60);
  --red-400:    oklch(0.704 0.190 22);
  --red-600:    oklch(0.577 0.240 27);
  --red-700:    oklch(0.505 0.210 27);
  --green-400:  oklch(0.792 0.180 152);
  --green-600:  oklch(0.596 0.150 150);
  --green-700:  oklch(0.527 0.135 150);
  --green-800:  oklch(0.448 0.119 151);
  --violet-500: oklch(0.606 0.220 293);
  --violet-700: oklch(0.491 0.230 292);
  --rose-500:   oklch(0.645 0.220 16);
  --orange-500: oklch(0.705 0.190 48);
  --orange-700: oklch(0.553 0.170 45);
  --slate-500:  oklch(0.554 0.046 257);
}

/* ---------- 2. Semantic colour — light ---------- */
:root {
  color-scheme: light;

  --background: var(--neutral-50);
  --foreground: var(--neutral-950);
  --surface: var(--neutral-0);
  --surface-foreground: var(--neutral-950);
  --surface-elevated: var(--neutral-0);
  --surface-elevated-foreground: var(--neutral-950);
  --muted: var(--neutral-100);
  --muted-foreground: var(--neutral-600);
  --primary: var(--blue-600);
  --primary-foreground: var(--neutral-0);
  --secondary: var(--neutral-100);
  --secondary-foreground: var(--neutral-900);
  --accent: var(--neutral-100);
  --accent-foreground: var(--neutral-900);
  --destructive: var(--red-600);
  --destructive-foreground: var(--neutral-0);
  --warning: var(--amber-800);
  --warning-foreground: var(--neutral-0);
  --success: var(--green-700);
  --success-foreground: var(--neutral-0);
  --info: var(--blue-700);
  --info-foreground: var(--neutral-0);
  --border: var(--neutral-200);
  --input: var(--neutral-500);   /* WCAG 1.4.11: control boundary needs 3:1; neutral-300 gave 1.4:1 */
  --ring: var(--blue-600);
  --overlay: oklch(0.145 0.01 250 / 0.6);

  --chart-1: var(--blue-600);
  --chart-2: var(--teal-700);    /* teal-500 gave 2.5:1 on white */
  --chart-3: var(--amber-600);
  --chart-4: var(--violet-500);
  --chart-5: var(--rose-500);
  --chart-6: var(--slate-500);

  /* ticket status: tinted background + dark foreground (badge style) */
  --status-open:                oklch(0.93 0.03 250);  --status-open-foreground:        var(--blue-700);
  --status-assigned:            oklch(0.93 0.05 293);  --status-assigned-foreground:    var(--violet-700);
  --status-in-progress:         oklch(0.94 0.06 84);   --status-in-progress-foreground: var(--amber-800);
  --status-pending:             var(--neutral-100);    --status-pending-foreground:     var(--neutral-700);
  --status-resolved:            oklch(0.94 0.06 152);  --status-resolved-foreground:    var(--green-800); /* green-700 gave 4.3:1 */
  --status-closed:              var(--neutral-200);    --status-closed-foreground:      var(--neutral-600);

  /* priority: solid fills with white text (P1..P3) and neutral P4 */
  --priority-p1: var(--red-600);     --priority-p1-foreground: var(--neutral-0);
  --priority-p2: var(--orange-700);  --priority-p2-foreground: var(--neutral-0);
  --priority-p3: var(--blue-700);    --priority-p3-foreground: var(--neutral-0);
  --priority-p4: var(--neutral-200); --priority-p4-foreground: var(--neutral-800);

  --sla-ok: var(--green-700);        --sla-ok-foreground: var(--neutral-0);
  --sla-warning: var(--amber-800);   --sla-warning-foreground: var(--neutral-0);
  --sla-breached: var(--red-600);    --sla-breached-foreground: var(--neutral-0);
  --sla-paused: var(--neutral-500);  --sla-paused-foreground: var(--neutral-0);

  --sidebar: var(--neutral-0);
  --sidebar-foreground: var(--neutral-900);
  --sidebar-accent: var(--neutral-100);
  --sidebar-accent-foreground: var(--neutral-900);
  --sidebar-border: var(--neutral-200);

  --shadow-1: 0 1px 2px oklch(0 0 0 / 0.06), 0 1px 3px oklch(0 0 0 / 0.08);
  --shadow-2: 0 4px 12px oklch(0 0 0 / 0.10), 0 2px 4px oklch(0 0 0 / 0.06);
  --shadow-3: 0 16px 40px oklch(0 0 0 / 0.16), 0 4px 12px oklch(0 0 0 / 0.08);
}

/* ---------- 2b. Semantic colour — dark ---------- */
[data-theme="dark"] {
  color-scheme: dark;

  --background: var(--neutral-950);
  --foreground: var(--neutral-50);
  --surface: var(--neutral-900);
  --surface-foreground: var(--neutral-50);
  --surface-elevated: var(--neutral-800);
  --surface-elevated-foreground: var(--neutral-50);
  --muted: var(--neutral-800);
  --muted-foreground: var(--neutral-400);
  --primary: var(--blue-400);
  --primary-foreground: var(--neutral-950);
  --secondary: var(--neutral-800);
  --secondary-foreground: var(--neutral-50);
  --accent: var(--neutral-800);
  --accent-foreground: var(--neutral-50);
  --destructive: var(--red-400);
  --destructive-foreground: var(--neutral-950);
  --warning: var(--amber-400);
  --warning-foreground: var(--neutral-950);
  --success: var(--green-400);
  --success-foreground: var(--neutral-950);
  --info: var(--blue-400);
  --info-foreground: var(--neutral-950);
  --border: var(--neutral-700);
  --input: var(--neutral-500);   /* neutral-600 gave 2.6:1 */
  --ring: var(--blue-400);
  --overlay: oklch(0 0 0 / 0.7);

  --chart-1: var(--blue-400);
  --chart-2: oklch(0.78 0.12 190);
  --chart-3: var(--amber-400);
  --chart-4: oklch(0.75 0.16 293);
  --chart-5: oklch(0.75 0.17 16);
  --chart-6: oklch(0.72 0.04 257);

  --status-open:        oklch(0.30 0.06 250); --status-open-foreground:        oklch(0.85 0.09 250);
  --status-assigned:    oklch(0.30 0.08 293); --status-assigned-foreground:    oklch(0.85 0.10 293);
  --status-in-progress: oklch(0.32 0.07 84);  --status-in-progress-foreground: oklch(0.88 0.12 84);
  --status-pending:     var(--neutral-800);   --status-pending-foreground:     var(--neutral-300);
  --status-resolved:    oklch(0.30 0.07 152); --status-resolved-foreground:    oklch(0.85 0.13 152);
  --status-closed:      var(--neutral-800);   --status-closed-foreground:      var(--neutral-400);

  --priority-p1: var(--red-400);     --priority-p1-foreground: var(--neutral-950);
  --priority-p2: var(--orange-500);  --priority-p2-foreground: var(--neutral-950);
  --priority-p3: var(--blue-400);    --priority-p3-foreground: var(--neutral-950);
  --priority-p4: var(--neutral-700); --priority-p4-foreground: var(--neutral-100);

  --sla-ok: var(--green-400);        --sla-ok-foreground: var(--neutral-950);
  --sla-warning: var(--amber-400);   --sla-warning-foreground: var(--neutral-950);
  --sla-breached: var(--red-400);    --sla-breached-foreground: var(--neutral-950);
  --sla-paused: var(--neutral-400);  --sla-paused-foreground: var(--neutral-950);

  --sidebar: var(--neutral-900);
  --sidebar-foreground: var(--neutral-100);
  --sidebar-accent: var(--neutral-800);
  --sidebar-accent-foreground: var(--neutral-50);
  --sidebar-border: var(--neutral-700);

  /* dark elevation: lower alpha plus a hairline instead of a soft shadow */
  --shadow-1: 0 0 0 1px oklch(1 0 0 / 0.06);
  --shadow-2: 0 0 0 1px oklch(1 0 0 / 0.08), 0 8px 24px oklch(0 0 0 / 0.5);
  --shadow-3: 0 0 0 1px oklch(1 0 0 / 0.10), 0 24px 60px oklch(0 0 0 / 0.6);
}

/* shadcn/ui aliases, on every theme scope so nested [data-theme] islands re-resolve */
:root, [data-theme] {
  --card: var(--surface);                 --card-foreground: var(--surface-foreground);
  --popover: var(--surface-elevated);     --popover-foreground: var(--surface-elevated-foreground);
  --sidebar-primary: var(--primary);      --sidebar-primary-foreground: var(--primary-foreground);
  --sidebar-ring: var(--ring);
  --shadow-focus: 0 0 0 var(--ring-offset) var(--background), 0 0 0 calc(var(--ring-offset) + var(--ring-width)) var(--ring);
}

/* ---------- 3. Shape, elevation, motion, sizing (theme-independent) ---------- */
:root {
  --radius: 0.5rem;
  --radius-control: calc(var(--radius) - 2px);
  --radius-card: var(--radius);
  --radius-modal: calc(var(--radius) + 4px);
  --radius-badge: 0.25rem;
  --radius-full: 9999px;
  --border-width-hairline: 1px;
  --border-width-strong: 2px;
  --shadow-0: none;
  --overlay-blur: 2px;
  --icon-xs: 12px; --icon-sm: 16px; --icon-md: 20px; --icon-lg: 24px;
  --sidebar-width: 16rem;
  --sidebar-width-collapsed: 3.5rem;
  --topbar-height: 3.5rem;
  --content-max-width: 90rem;
  --z-base: 0; --z-sticky: 10; --z-dropdown: 20; --z-overlay: 30; --z-modal: 40; --z-toast: 50; --z-tooltip: 60;
  --duration-fast: 120ms; --duration-normal: 200ms; --duration-slow: 320ms;
  --ease-standard: cubic-bezier(0.2, 0, 0, 1);
  --ease-enter: cubic-bezier(0, 0, 0.2, 1);
  --ease-exit: cubic-bezier(0.4, 0, 1, 1);
  --opacity-disabled: 0.5; --opacity-muted: 0.7; --opacity-overlay: 0.6;
  --ring-width: 2px; --ring-offset: 2px;
}

/* ---------- 4. Density (component tokens; spacing.md §Density mapping) ---------- */
/* :root comes first so [data-density="compact"] (same specificity, later) wins on <html>. */
:root, [data-density="comfortable"] {
  --density: 1;           --spacing: 0.25rem;
  --control-sm: 32px;     --control-md: 36px;          --control-lg: 40px;
  --table-row-height: 44px; --table-header-height: 40px; --sidebar-item-height: 36px;
  --card-padding: 24px;   --form-field-gap: 16px;
  --badge-padding-inline: 8px; --timeline-gap: 16px;
}
[data-density="compact"] {
  --density: 0.82;        --spacing: 0.22rem;
  --control-sm: 28px;     --control-md: 32px;          --control-lg: 36px;
  --table-row-height: 36px; --table-header-height: 32px; --sidebar-item-height: 32px;
  --card-padding: 20px;   --form-field-gap: 12px;
  --badge-padding-inline: 6px; --timeline-gap: 12px;
}

/* ---------- 5. Reduced motion ---------- */
@media (prefers-reduced-motion: reduce) {
  :root { --duration-fast: 0ms; --duration-normal: 0ms; --duration-slow: 0ms; }
}

/* ---------- 6. Theme-independent Tailwind values (emitted as variables) ---------- */
@theme {
  --font-sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans",
    sans-serif, "Apple Color Emoji", "Segoe UI Emoji";
  --font-mono: ui-monospace, "SFMono-Regular", Menlo, Consolas, "Liberation Mono", monospace;
  --text-xs: 0.75rem;   --text-xs--line-height: 1rem;
  --text-sm: 0.875rem;  --text-sm--line-height: 1.25rem;
  --text-base: 1rem;    --text-base--line-height: 1.5rem;
  --text-lg: 1.125rem;  --text-lg--line-height: 1.75rem;
  --text-xl: 1.25rem;   --text-xl--line-height: 1.75rem;
  --text-2xl: 1.5rem;   --text-2xl--line-height: 2rem;
  --text-3xl: 1.875rem; --text-3xl--line-height: 2.25rem;
  --font-weight-regular: 400; --font-weight-medium: 500; --font-weight-semibold: 600; --font-weight-bold: 700;
  --tracking-tight: -0.01em; --tracking-normal: 0; --tracking-wide: 0.02em;
}

/* ---------- 7. Semantic tokens → Tailwind utilities (resolved at runtime) ---------- */
@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --color-surface: var(--surface);                 --color-surface-foreground: var(--surface-foreground);
  --color-surface-elevated: var(--surface-elevated); --color-surface-elevated-foreground: var(--surface-elevated-foreground);
  --color-card: var(--card);                       --color-card-foreground: var(--card-foreground);
  --color-popover: var(--popover);                 --color-popover-foreground: var(--popover-foreground);
  --color-muted: var(--muted);                     --color-muted-foreground: var(--muted-foreground);
  --color-primary: var(--primary);                 --color-primary-foreground: var(--primary-foreground);
  --color-secondary: var(--secondary);             --color-secondary-foreground: var(--secondary-foreground);
  --color-accent: var(--accent);                   --color-accent-foreground: var(--accent-foreground);
  --color-destructive: var(--destructive);         --color-destructive-foreground: var(--destructive-foreground);
  --color-warning: var(--warning);                 --color-warning-foreground: var(--warning-foreground);
  --color-success: var(--success);                 --color-success-foreground: var(--success-foreground);
  --color-info: var(--info);                       --color-info-foreground: var(--info-foreground);
  --color-border: var(--border);
  --color-input: var(--input);
  --color-ring: var(--ring);
  --color-overlay: var(--overlay);
  --color-chart-1: var(--chart-1); /* … through --color-chart-6 */
  --color-status-open: var(--status-open); --color-status-open-foreground: var(--status-open-foreground);
  /* … the same pair for every status, priority-p1..p4 and sla-ok/warning/breached/paused */
  --color-sidebar: var(--sidebar);                 --color-sidebar-foreground: var(--sidebar-foreground);
  --color-sidebar-primary: var(--sidebar-primary); --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
  --color-sidebar-accent: var(--sidebar-accent);   --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
  --color-sidebar-border: var(--sidebar-border);   --color-sidebar-ring: var(--sidebar-ring);

  --font-heading: var(--font-sans);

  /* shadcn radius scale, derived from --radius */
  --radius-sm: calc(var(--radius) * 0.6);
  --radius-md: calc(var(--radius) * 0.8);
  --radius-lg: var(--radius);
  --radius-xl: calc(var(--radius) * 1.4);
  --radius-2xl: calc(var(--radius) * 1.8);
  --radius-3xl: calc(var(--radius) * 2.2);
  --radius-4xl: calc(var(--radius) * 2.6);

  --blur-overlay: var(--overlay-blur);
}

/* Tokens whose Tailwind name equals the token name */
@theme reference {
  --radius-control: var(--radius-control);
  --radius-card: var(--radius-card);
  --radius-modal: var(--radius-modal);
  --radius-badge: var(--radius-badge);
  --shadow-1: var(--shadow-1); --shadow-2: var(--shadow-2); --shadow-3: var(--shadow-3);
  --ease-standard: var(--ease-standard); --ease-enter: var(--ease-enter); --ease-exit: var(--ease-exit);
}
```

`--spacing` is Tailwind v4's spacing multiplier: `p-4` = `calc(var(--spacing) * 4)`, so the density scope rescales every spacing utility at once without touching classes. Non-namespaced tokens (`--control-md`, `--z-modal`, `--duration-*`) are read with arbitrary values (`h-[var(--control-md)]`, `z-[var(--z-modal)]`, `duration-[var(--duration-normal)]`) or inside component CSS.

Three `@theme` blocks exist because of how Tailwind emits variables:

| Block | Emits a `--x` variable | Utility reads | Used for |
|---|---|---|---|
| `@theme` | yes | `var(--x)` | static values: fonts, text sizes, weights, tracking |
| `@theme inline` | yes | the declared value, e.g. `var(--background)` | `--color-*` and other names that differ from the source token |
| `@theme reference` | no | `var(--x)` | names equal to the source token (`rounded-card`, `shadow-2`, `ease-standard`) |

A same-name entry such as `--shadow-2: var(--shadow-2)` must not sit in `@theme` or `@theme inline`. Both emit the circular declaration `--shadow-2: var(--shadow-2)`, which makes the token invalid at runtime and drops the dark shadows. `@theme reference` generates the utility without emitting the variable. `@theme inline reference` would work too, but Biome cannot parse two options.

## Contrast pairs to verify

`pnpm tokens:check` runs `frontend/scripts/check-contrast.ts` with `node --experimental-strip-types`. The script has no dependencies. It reads `tokens.css`, resolves the light (`:root`) and dark (`[data-theme="dark"]`) semantic tokens down to oklch primitives, and computes the WCAG 2.2 contrast ratio with `src/lib/theme/contrast.ts` (oklch → OKLab → linear sRGB, out-of-gamut channels clipped). It exits 1 when any pair is below its requirement. Targets: 4.5:1 for text, 3:1 for UI boundaries and chart series.

The script checks 52 pairs in both themes, 104 checks in total. All 104 pass. The table groups them; values are the script's output, rounded down.

| Pair | Light | Dark | Requirement |
|---|---|---|---|
| foreground on background | 18.9:1 | 18.9:1 | 4.5 |
| muted-foreground on background, muted, surface | ≥ 6.8:1 | ≥ 5.8:1 | 4.5 |
| `-foreground` on its fill: surface, surface-elevated, secondary, accent, sidebar | ≥ 16.2:1 | ≥ 14.4:1 | 4.5 |
| primary-foreground on primary | 5.0:1 | 7.6:1 | 4.5 |
| destructive-foreground on destructive | 4.8:1 | 6.8:1 | 4.5 |
| warning-foreground on warning | 7.0:1 | 11.5:1 | 4.5 |
| success-foreground on success | 5.0:1 | 11.0:1 | 4.5 |
| info-foreground on info | 6.6:1 | 7.6:1 | 4.5 |
| status-*-foreground on status-* (six) | ≥ 5.3:1 | ≥ 5.8:1 | 4.5 |
| priority-p1..p4 foreground on fill | ≥ 4.8:1 | ≥ 6.8:1 | 4.5 |
| sla-* foreground on fill | ≥ 4.7:1 | ≥ 6.8:1 | 4.5 |
| primary, destructive, warning, success, info, sla-ok/warning/breached as text on background and surface | ≥ 4.6:1 | ≥ 6.2:1 | 4.5 |
| input on background | 4.5:1 | 4.1:1 | 3 |
| ring on background | 4.8:1 | 7.6:1 | 3 |
| chart-1..6 on surface | ≥ 3.3:1 | ≥ 6.9:1 | 3 |

These values were changed to pass the check:

| Token | Was | Now | Reason |
|---|---|---|---|
| `--input` (light) | `--neutral-300` | `--neutral-500` | 1.4:1; a control boundary needs 3:1 (WCAG 1.4.11) |
| `--input` (dark) | `--neutral-600` | `--neutral-500` | 2.6:1 |
| `--chart-2` (light) | `--teal-500` | `--teal-700` | 2.5:1 on white |
| `--status-resolved-foreground` (light) | `--green-700` | `--green-800` (new primitive) | 4.3:1 on the resolved tint |

`--border` is decorative and is not checked. Some colours sit outside sRGB; the script clips them and lists them under the table. The same functions will validate tenant primary colours (§Tenant branding).

## Density

`<html data-density="comfortable|compact">` is set by the boot script in `index.html` and then by `ThemeProvider`, from `localStorage['sh.density']`. Each density scope sets `--spacing`, `--density` and every component size explicitly. Sizes are not computed as `size × --density`, because 0.82 × 32/36/40px does not give the 28/32/36px in [spacing](spacing.md). `--density` stays available as a factor but no token derives from it.

Rule order matters. `:root, [data-density="comfortable"]` comes first and `[data-density="compact"]` second. Both have the same specificity on `<html>`, so the later compact rule wins.

Typography sizes are unchanged (compact does not shrink text). Table virtualisation is not needed because row height is a token, not measured.

## Tenant branding (V1 hook, MVP-ready)

Tenant settings expose `branding.primary` (oklch or hex) and `branding.logo_url` (light and dark variants). At boot the SPA injects:

```css
:root[data-tenant] { --primary: <tenant primary>; --primary-foreground: <computed>; --ring: <tenant primary>; --chart-1: <tenant primary>; }
```

`--primary-foreground` is computed client-side (`pickForeground` in `src/lib/theme/contrast.ts`, contrast against `--neutral-0` and `--neutral-950`, choose the higher; reject the colour in settings if neither reaches 4.5:1). Because every utility already resolves `var(--primary)` at runtime, no rebuild is needed. Full white-label (fonts, radius, all semantic colours) is deferred; the token layering makes it a settings form, not a code change.
