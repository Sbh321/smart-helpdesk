# Design system principles

The design system is the set of tokens, primitives and compositions the SPA is built from. Decisions: [ADR-0002](../adr/0002-ui-primitive-strategy.md) (shadcn on Base UI, Tailwind v4 tokens). Pages: [tokens](tokens.md) · [themes](themes.md) · [typography](typography.md) · [spacing](spacing.md) · [components](components.md) · [page patterns](page-patterns.md) · [accessibility](accessibility.md) · [data visualisation](data-visualization.md) · [responsive](responsive.md) · [UX review](ux-review.md).

**D1, D2 and D9 are enforced, not trusted** (roadmap M4-01). `pnpm lint` fails on: a feature importing `@base-ui/react`, `recharts`, `sonner`, `react-day-picker`, `@tanstack/react-table` or `cmdk` (Biome `noRestrictedImports` per layer — features use `@/components/ui` and `@/components/shared`; only `components/ui` wraps Base UI and sonner); and any literal colour, Tailwind palette class, arbitrary radius or arbitrary z-index in `src` (`scripts/check-design-tokens.ts`). A literal that is an input rather than styling — the brand colour picker's default — carries `// design-tokens-allow: <reason>` on the line above.

## Principles

| # | Principle | What it means in practice |
|---|---|---|
| D1 | **Token-driven, never literal** | No hard-coded colours, sizes, radii or shadows in components. Every visual value is a CSS custom property defined in `styles/tokens.css` and exposed as a Tailwind utility via `@theme inline`. A PR adding `#fff`, `bg-gray-100` or `rounded-[6px]` is rejected. |
| D2 | **One primitive layer** | Base UI through shadcn/ui only. No Radix, React Aria, Headless UI or hand-rolled popovers. Pickers use Base UI `Combobox`; `cmdk` exists only inside `CommandPalette`. |
| D3 | **Semantic colour is reserved** | Status, priority and SLA colours mean exactly one thing each. Marketing-style colour is not used; the default UI is neutral so that the six status colours, four priority colours and three SLA colours carry information. |
| D4 | **Density-aware** | Every control and row reads its height and padding from density tokens; `data-density="compact"` on `<html>` rescales the whole app. Agents processing queues want compact; admins want comfortable. |
| D5 | **Explainability surfaces are first-class** | `ExplanationPanel`, `PriorityBadge` with breakdown popover and the assignment shortlist are core components, not afterthoughts. The academic demonstration lives in them. |
| D6 | **Keyboard-first for agents** | Every action reachable without a mouse; global and list shortcuts documented in [accessibility](accessibility.md); focus ring always visible on `:focus-visible`. |
| D7 | **Three theme modes, zero flash** | Light, dark and system are resolved before first paint; charts, logos and images follow the theme. |
| D8 | **States are part of the component** | Loading (skeleton), empty, error and forbidden states ship with each list/detail composition. A feature is not done until all four render ([definition of done](../10-quality/definition-of-done.md)). |
| D9 | **Own the source** | shadcn components are copied into `src/components/ui`; we edit them (tokens, a11y fixes) rather than wrapping them. Upstream updates are diffed in, not pulled blindly. |
| D10 | **Logical properties** | `ps-*`, `pe-*`, `ms-*`, `me-*`, `start`/`end` instead of left/right, so RTL is a flag later, not a restyle. |

## How to add a token

1. Decide the layer: primitive (a raw oklch value, rarely added), semantic (a meaning like `--sla-warning`), or component (a value only one component reads, e.g. `--table-row-height`).
2. Add the light value under `:root` and the dark value under `[data-theme="dark"]` in `styles/tokens.css`. Check contrast with the pairs table in [tokens.md](tokens.md).
3. If it must generate utilities, map it in the `@theme inline` block (`--color-sla-warning: var(--sla-warning);` → `bg-sla-warning`, `text-sla-warning`).
4. Document it in [tokens.md](tokens.md) in the same commit.
5. Never reference a primitive from a component; components use semantic or component tokens only.

## How to add a component

1. Prefer an existing shadcn component: `pnpm dlx shadcn@latest add <name>` (Base UI base is pinned in `components.json`).
2. If a composition is needed, create it in `src/components/shared/<Name>/` with `index.ts`, `<Name>.tsx`, `<Name>.test.tsx` (Vitest browser mode where focus/layout matters), and a states story-less demo in the `/dev/components` route (dev-only).
3. Props accept `className` and forward refs; visual values come from tokens; interactive parts come from Base UI.
4. Strings go to `src/copy/en.ts`; icons from `lucide-react` with `aria-hidden` (the default) plus a visible or `sr-only` label.
5. Run the axe scan (`pnpm e2e --grep a11y`) on the route that uses it.

## Non-goals in the MVP

Storybook, a published registry, per-tenant full white-labelling (only logo and primary colour), custom fonts per tenant, mobile-first layouts. Each is listed in the [V1 backlog](../../roadmap/09-v1-backlog.md).
