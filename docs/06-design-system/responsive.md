# Responsive behaviour

What the SPA does at each width. Pages: [principles](principles.md) · [page patterns](page-patterns.md) · [accessibility](accessibility.md) · [UX review G8](ux-review.md#g8--responsive-below-the-laptop-breakpoint). Introduced by roadmap [M4-13](../../roadmap/05-ui-revamp.md).

The agent app is desktop-first. It is designed for 1280 px and up, deliberate from 1024 px, and checked not to break at 768 px. There is no mobile layout below 768 px (V1, see [accessibility](accessibility.md)).

| Width | Navigation | Ticket context | Tables | Checked by |
|---|---|---|---|---|
| ≥ 1280 px (`xl`, `WIDE_QUERY`) | full sidebar with group headings; the collapse choice is remembered | column beside the conversation | all default columns fit | the whole browser suite runs at 1280 × 800 |
| 1024 – 1279 px | icon rail by default (labels as tooltips and accessible names); can be opened for the visit | a **drawer** from the right, opened by "Ticket context" in the actions row | the queue scrolls inside its own container when columns do not fit; the page never scrolls sideways | `states-and-widths.browser.test.tsx` at 1024 × 800, including axe |
| 768 – 1023 px | icon rail | drawer | as above; toolbars wrap | a Playwright measure of 13 main pages reported no horizontal page overflow |
| < 768 px | not designed | — | — | — |

## Rules

- **The page never scrolls sideways.** Wide content (tables, code, charts) scrolls inside its own container, which is keyboard-reachable.
- **One breakpoint for layout changes**, `WIDE_QUERY` = `(min-width: 80rem)` in `lib/use-media-query.ts`, used where the layout changes structure (rail vs sidebar, drawer vs column). Tailwind's `xl:` is the same width for pure CSS changes.
- **Collapse, do not hide.** Below 1280 px nothing disappears: the navigation keeps every destination as an icon with its name, and the ticket context keeps every section in the drawer.
- **Tests run at the design width.** The browser suite runs at 1280 × 800; narrower widths are covered by the explicit tests above, not by resizing every test.
