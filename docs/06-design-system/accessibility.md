# Accessibility

Target: **WCAG 2.2 Level AA** for the agent application at 1280px and above, with keyboard operability as a first-class product requirement (NFR-UX-02..04). Base UI provides the interaction primitives; this page lists what we add and how it is verified.

## Provided by Base UI / shadcn

| Area | Provided |
|---|---|
| Dialogs, alert dialogs, sheets | focus trap, initial focus, focus return to the trigger, `Esc` to close, `aria-modal`, labelled by title |
| Menus, popovers, tooltips | roving focus, typeahead, `Esc`, `aria-expanded`/`aria-controls`, tooltip on focus and hover |
| Select, Combobox, Autocomplete | listbox semantics, `aria-activedescendant`, keyboard filtering, announce selection |
| Tabs, toggle groups, switches, checkboxes, radios | APG keyboard patterns and roles |
| Field | label association, `aria-describedby` for description and error, `aria-invalid` |
| Toast (sonner) | `role="status"` region |
| Charts (Recharts) | `accessibilityLayer`: keyboard navigation between points and ARIA labels |

## Added by us

| Requirement | Implementation |
|---|---|
| Skip link | first focusable element: "Skip to content" → `#main` |
| Landmarks | `<header>` topbar, `<nav aria-label="Primary">` sidebar, `<main id="main">`, `<aside aria-label="Ticket details">` on the ticket page, `<footer>` in tables for pagination (`aria-label="Pagination"`) |
| Page titles and headings | `document.title` per route (`Ticket #1042 · Acme Helpdesk`), one `h1` per page, ordered levels |
| Focus visibility | `:focus-visible` ring from `--ring`/`--ring-width`/`--ring-offset` on every interactive element; never `outline: none` without a replacement |
| Route changes | focus moved to the `h1` of the new page; route announcer `aria-live="polite"` reads the title |
| Live regions | toasts (sonner), notification bell ("3 new notifications"), SLA badge state changes ("SLA warning: resolution due in 45 minutes"), table result count after filtering ("128 tickets") — all `polite`; breach events on the open ticket use `assertive` |
| Reduced motion | `prefers-reduced-motion` zeroes duration tokens; `tw-animate-css` animations disabled via the same media query; sparklines render without animation |
| Tables | `<table>` semantics, `<th scope="col">`, sortable headers as buttons with `aria-sort`, row selection checkboxes with labels ("Select ticket #1042"), caption (visually hidden) describing the table, keyboard navigation announces the focused row |
| Forms | every input labelled (visible label or `aria-label` for search), errors associated via Field, error summary focused on submit failure, required marked in text not colour only |
| Colour independence | status, priority and SLA badges always carry an icon and text; charts have a legend and a visually hidden data table; SLA countdown shows "overdue" text, not only red |
| Contrast | token pairs verified by `pnpm tokens:check` ([tokens.md](tokens.md)) in both themes |
| Target size | controls ≥ 24×24 CSS px (WCAG 2.2 2.5.8); compact density keeps 28px minimum |
| Timing | no auto-logout without warning; SLA countdowns are informational |
| Language | `<html lang="en">`; strings from `copy/en.ts` |
| Markdown content | sanitised; links distinguishable by underline, not colour only |
| Icons | `lucide-react` defaults to `aria-hidden`; icon-only buttons have `aria-label` and a tooltip |

## Keyboard map

Global (disabled while typing in inputs except `Esc`):

| Keys | Action |
|---|---|
| `/` | focus search / filter bar |
| `⌘K` / `Ctrl+K` | command palette |
| `g` then `d` / `t` / `c` / `s` | go to dashboard / tickets / contacts / settings |
| `n` | new ticket |
| `?` | shortcut help dialog |
| `Esc` | close dialog/popover, clear selection |

Ticket list:

| Keys | Action |
|---|---|
| `j` / `k` | move row focus down / up |
| `Enter` or `o` | open focused ticket |
| `x` | toggle selection; `Shift+x` select range |
| `a` | assign selected (dialog) |
| `s` | change status of selected |

Ticket page:

| Keys | Action |
|---|---|
| `r` | focus reply composer (public) |
| `i` | focus composer in internal-note mode |
| `Ctrl+Enter` | submit comment |
| `e` | edit ticket fields |
| `a` | assign |
| `p` | change priority (opens dialog with explanation) |
| `[` / `]` | previous / next ticket in the current list |

Shortcuts are registered in one `useShortcuts` hook from a table in `copy/shortcuts.ts` so the help dialog and the implementation cannot drift; every shortcut has a mouse-reachable equivalent.

## Testing

| Level | What |
|---|---|
| Automated E2E | `@axe-core/playwright` on: login, dashboard, ticket list (with data), ticket page, settings/automation, developer settings — in light and dark; CI fails on `serious`/`critical` violations |
| Component (Vitest browser) | `DataTable` sort header `aria-sort`; dialog focus return; combobox keyboard selection; `SlaBadge` live region only on state change |
| Manual checklist (release) | keyboard-only golden path (login → create ticket → assign → reply → resolve); screen reader pass (NVDA + Firefox, VoiceOver + Safari) over the same path; 200 % zoom without horizontal scroll at 1280px; Windows High Contrast (forced-colors) sanity |
| Lint | Biome a11y rules enabled (`useKeyWithClickEvents`, `useButtonType`, `noSvgWithoutTitle` off for decorative icons) |

## Known gaps (MVP)

| Gap | Plan |
|---|---|
| Mobile/touch layouts below 768px | V1 (agent app is desktop-first) |
| Full screen-reader verification of Recharts point navigation | rely on the hidden data table; audit in V1 |
| Markdown editor is a plain textarea | acceptable; rich toolbar V1 |
| Drag-and-drop upload has a file-input fallback but no keyboard reordering | reordering not needed |
| Language switching / RTL | logical properties used; i18n V1 |
| Colour-blind safe chart palette beyond six series | limit to six series; patterns V1 |
