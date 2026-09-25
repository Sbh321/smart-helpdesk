# Frontend ecosystem research (React / TypeScript / Vite)

Researched 2026-09-17 against registry.npmjs.org dist-tags and primary docs. Selected constraints in [versions.md](versions.md). Classification per package: **Required for MVP**, **Useful for MVP**, **V1**, **Future**, **Rejected**.

## Core

| Item | Finding | Decision |
|---|---|---|
| React | 19.3.0 (2026-09-09). No React 20. `Activity` (19.2), `ViewTransition` and Fragment Refs (19.3 stable) are polish, not MVP. | **19.3** |
| Vite | 8.3.0; Vite 8 (2026-03) is Rolldown-based; `@vitejs/plugin-react` 6 uses Oxc and **no longer accepts Babel plugin config**; React Compiler enabled via `react({ compiler: true })` + `oxc-transform-react`. Built-in TS path alias resolution. Node ≥ 20.19. | **Vite 8**, compiler on |
| TypeScript | 7.0.2 (Go compiler, 2026-07-08) but no stable programmatic API until 7.1, so typescript-eslint cannot consume it. | **TS 7** with Biome (no typescript-eslint) |
| Node | 24 is Active LTS (v26 becomes LTS 2026-10-28). | **Node 24** pinned; bump after MVP |

## Styling and components

| Item | Finding | Decision |
|---|---|---|
| Tailwind | 4.3.3. CSS-first config: `@theme` generates utilities from tokens; `@theme inline` maps utilities to runtime `var(--x)` so light/dark scopes re-resolve; `@custom-variant dark` for class/attribute dark mode. | **Tailwind 4** |
| shadcn/ui | CLI 4.21.0. **Base UI became the default primitive in July 2026** (`-b radix` opts out; React Aria is a third base). Every component was rebuilt for Base UI with the same API. Theming convention: surface/`-foreground` pairs in `:root`/`.dark` exposed through `@theme inline`. Registry supports themes/tokens, private registries. `cn` now a package. | **shadcn on Base UI** |
| Base UI | `@base-ui/react` 1.8.0 (1.0 GA 2025-12-11; package renamed from `@base-ui-components/react`). MUI-maintained by the original Radix authors; ~35 APG-based components including first-party Combobox/Autocomplete. | one primitive layer only |
| Radix | ~1.1.x, still maintained by WorkOS, but momentum moved. | Rejected (no mixing) |
| React Aria | third shadcn base; heaviest API. | Rejected |
| cmdk | last release 2025-03; still used by shadcn `command`. | Useful for ⌘K palette only; **in-form pickers use Base UI Combobox** |
| lucide-react | 1.47.0 (1.0 in June 2026 removed brand icons, `aria-hidden` default). | **Required** |
| sonner 2.0.8 | shadcn toast. | **Required** |
| react-day-picker 10 | shadcn calendar for date-range filters. | Useful |
| vaul, embla, input-otp | stale or unneeded. | Rejected |
| VitePress 1.6.4 (+ Vue 3.5.43) | Static documentation site generator on Vite: Markdown in place, local full-text search, light/dark, `rewrites` for `README.md` overviews, dead-link check at build. The sibling support-saas platform renders its docs the same way. Separate package in `docs/` (own lock file), used only at build time. | **Platform docs site (M5-05)**; not part of the application bundle |
| Mermaid 11.17.2 | Renders the `mermaid` code fences of `docs/` in the browser; the same major line as `@mermaid-js/mermaid-cli` in the report pipeline, so diagrams look alike in both. Mermaid 12.0 (2026-09-10) is two weeks old. | **Pinned 11.17.2** in `docs/` |
| Tailark blocks (github.com/tailark/blocks, MIT, 2025-07) | Copy-paste marketing sections (hero, features, FAQ, call to action, footer, login) built on shadcn and Tailwind 4, with a **Base UI** variant of every kit (Dusk, Mist, Veil). Source to adapt, not a package: blocks import `next/link`, `next/image` and a few `motion/react` animations, all replaced here. | **Adapted for the landing site (M5-04)**: Mist kit layouts rewritten on our tokens and `Button`, no `motion`; MIT notice served at `/third-party-licences.txt` and kept in the source header. No new dependency |

Decision recorded in [ADR-0002](../adr/0002-ui-primitive-strategy.md).

## TanStack

| Package | Version | Classification | Notes |
|---|---|---|---|
| Router | 1.170.38 | **Required** | File-based routes via Vite plugin; typed, schema-validated search params make the URL the filter store; loaders call `queryClient.ensureQueryData`. Preferred over React Router 7 (no typed search params; team focus is Remix/full-stack). |
| Query | 5.103.1 | **Required** | All server state. v6 exists only for non-React adapters; avoid `data: T \| undefined` patterns that will churn. `placeholderData: keepPreviousData` for paging. |
| Table | 9.2.4 (v9 GA 2026-08-04) | **Required** | Tree-shakable features, store-based state. Nearly all tutorials are v8; budget half a day, `@tanstack/react-table@8` is the fallback. Server-side mode: `manualPagination/Sorting/Filtering`, `rowCount`. |
| Form | 1.33.5 | **Required** | Standard Schema: Zod 4 without adapters; shadcn has first-class docs. RHF 7.88 is fine but v8 is in beta and needs a resolver package. |
| Virtual | 3.14.13 | V1 | Not needed with 25–50 row server pages; add for long comment threads/infinite feeds. |
| Store | 0.11.1 | transitive only | Table v9 dependency; 0.x, not an app store. |
| Pacer | 0.23.0 | Rejected | A 6-line debounce hook suffices. |
| DB | 0.4.1 beta | Future | Local-first/offline; not our problem. |
| Start | — | N/A | SSR framework; we are an SPA on a Laravel API. |
| zod-adapter | 1.167.0 | **Required** | `validateSearch` with Zod for route search params. |

## State management decision

Server data → Query cache. Filters/sort/page/selection → Router search params. Theme → 30-line context + localStorage. Current user/tenant/permissions → context populated from `/v1/me` query. Dialog/sidebar → local state. **No global store library in the MVP**; add Zustand 5 only for a proven cross-tree need (draft buffers). Jotai 3.0 is nine days old; TanStack Store is 0.x. Recorded in [ADR-0003](../adr/0003-state-management.md).

## Validation, dates, charts

| Item | Decision | Reason |
|---|---|---|
| Zod 4.6.5 | **Required** (plain `zod`, not `zod/mini`) | 5 kB gz; Standard Schema; one schema for forms, search params and API edge parsing. |
| date-fns 4.4.0 + @date-fns/tz 1.5.0 | **Required** | First-class `TZDate`; tree-shakable; active. Luxon has not released since 2025-09; Day.js TZ is a plugin; Temporal lacks Safari. Wrap in `src/lib/datetime.ts`. |
| Recharts 3.10.1 via shadcn `chart` | **Required** | SVG themes from CSS variables (dark mode free), `accessibilityLayer` on by default, six chart types covered. ECharts (canvas, Apache-2.0, theming tax), Chart.js (canvas a11y), visx (toolkit), Nivo (pre-1.0, stale), Tremor (a layer on Recharts with its own look) rejected. |

## Testing and tooling

| Item | Decision |
|---|---|
| Vitest 5.0.1 + browser mode + vitest-browser-react 2.3.0 | **Required**: unit tests in node; component tests in a real browser (Base UI popovers/focus behave badly in jsdom). |
| Playwright 1.63.0 + @axe-core/playwright 4.13.0 | **Required** for E2E golden path, tenant isolation and axe scans of key routes. Playwright component testing (stable since 1.62) **Rejected** to keep one component runner. |
| MSW 2.15.0 | **Useful**: mock the API from the OpenAPI schema in tests and for frontend-first days. |
| vitest-axe | Rejected (0.1.0 from 2022). |
| Storybook 10.6.0 | V1 (when publishing our registry). |
| Biome 2.5.14 | **Required**: single lint+format tool; sidesteps typescript-eslint's TS 7 gap. Optional thin ESLint only for `eslint-plugin-react-hooks` 7 and `@tanstack/eslint-plugin-query` — deferred. |
| openapi-typescript 7.13.0 + openapi-fetch 0.17.0 | **Required**: types-only generation from Scramble's `/docs/api.json`; ~6 kB typed fetch. openapi-fetch is reportedly in maintenance mode; the types are the asset and the client is replaceable in an afternoon. Orval 8.33 (hooks + MSW mocks + Zod) is the alternative if generation should go further. hey-api 0.99 pre-1.0. |
| i18n | Deferred; strings live in `src/copy/en.ts`, `Intl` for numbers/dates, logical CSS properties for RTL readiness. |

## Sources

react.dev/versions and blog (19.2, 19.3, compiler 1.0); vite.dev/blog/announcing-vite8; github.com/vitejs/vite-plugin-react releases; oxc.rs react-compiler post; devblogs.microsoft.com TypeScript 7.0; nodejs release schedule; tailwindcss.com/docs (theme, dark-mode, v4.3 blog); ui.shadcn.com/docs (changelog 2026-07 base-ui-default, 2026-01, react-aria, theming, cli, registry, forms/tanstack-form, components/base/chart, command); base-ui.com releases; github.com/mui/base-ui; tanstack.com (router, query migrate v6, table v9 blog, form v1 blog, db); zod.dev/v4; recharts a11y wiki; vitest discussions 9664; playwright.dev/docs/test-components; storybook 10 blog; biome/eslint comparisons; lucide.dev/guide/version-1; registry.npmjs.org dist-tags (2026-09-17).
