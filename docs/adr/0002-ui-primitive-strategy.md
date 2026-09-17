# ADR-0002 UI primitives: shadcn/ui on Base UI with Tailwind v4 tokens

**Status:** Accepted (2026-09-17)

## Context

The brief asks for shadcn, Base UI, Tailwind and our own design system without two competing primitive layers. In July 2026 shadcn/ui made Base UI its default primitive (Radix and React Aria remain selectable). Base UI 1.x is maintained full-time by MUI and includes Combobox/Autocomplete, which Radix lacks. Research: [01-research/frontend-ecosystem.md](../01-research/frontend-ecosystem.md) §Styling.

## Decision

- **One primitive layer: Base UI 1.8+, consumed through shadcn/ui components** (`shadcn init` with the default base; `components.json` pins `base`). No `@radix-ui/*` or `react-aria-components` packages may be added.
- **Tailwind CSS 4** with CSS-first configuration. Semantic tokens are CSS custom properties on `:root` (light) and `[data-theme="dark"]` (dark), exposed as utilities through `@theme inline`; `@custom-variant dark` targets the data attribute. Token architecture in [06-design-system/tokens.md](../06-design-system/tokens.md).
- shadcn's surface/`-foreground` convention is adopted and extended with helpdesk semantics: `--status-*`, `--priority-*`, `--sla-*`, `--chart-*`, plus typography, spacing, radius, elevation, motion, z-index, icon-size, control-height and density tokens.
- Charts: **Recharts 3 through shadcn `chart`** (SVG, themed by the same variables, accessibility layer on).
- In-form pickers use Base UI Combobox; the ⌘K palette uses shadcn `command` (cmdk) and is contained in one file so it can be swapped.

## Alternatives considered

- shadcn on Radix: still maintained (WorkOS) but no longer the default; the original authors build Base UI; Combobox is a Popover+cmdk workaround. Rejected.
- React Aria Components: strongest formal a11y, heaviest API, smallest shadcn share. Rejected.
- Material UI / Mantine / Chakra: pre-styled systems that fight a token-driven custom theme. Rejected.
- ECharts / Chart.js: canvas rendering breaks CSS-variable theming and screen-reader access. Rejected.

## Consequences

- Components are owned source in `src/components/ui` (copied by the CLI), so tokens and accessibility fixes are ours to make.
- Light/dark/system and future tenant branding are pure CSS variable overrides.
- Copying code from 2024–2025 Radix-era tutorials is a hazard; the docs' Base UI tab is the reference.

## Migration / future considerations

`shadcn migrate` and the migration skill can move to Radix if Base UI disappoints; the component API is identical. A private shadcn registry can later distribute our tokens and helpdesk compositions to other apps.
