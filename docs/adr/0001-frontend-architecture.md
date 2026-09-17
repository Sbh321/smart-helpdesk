# ADR-0001 Frontend architecture: React SPA on Vite with the TanStack stack

**Status:** Accepted (2026-09-17)

## Context

The backend is API-only Laravel. The UI is data-heavy (ticket and contact tables with server-side paging/filtering/sorting, forms, dashboards) and must support light/dark/system theming and accessibility. One developer, three weeks; the code must remain the base for V1. Research: [01-research/frontend-ecosystem.md](../01-research/frontend-ecosystem.md).

## Decision

- **React 19.3 + TypeScript 7 + Vite 8** single-page application in `frontend/`, built to static assets served by the edge proxy. React Compiler enabled through `@vitejs/plugin-react` (`compiler: true`).
- **TanStack Router** (file-based routes, typed and Zod-validated search params) for routing; **TanStack Query** for all server state; **TanStack Table v9** for data tables (v8 as the fallback if the v9 documentation gap costs more than half a day); **TanStack Form** with **Zod 4** via Standard Schema for forms.
- Feature-oriented layout: `src/app` (providers, bootstrap), `src/routes` (file routes only, thin), `src/features/<feature>/{api,components,hooks,schemas}`, `src/components/{ui,shared,layout}`, `src/lib/{api,auth,datetime,theme,utils}`, `src/styles`, `src/copy`. No `stores/` or `entities/` folders (see ADR-0003). Import rules: `routes → features → components/lib`; features never import each other's internals, only their `index.ts`.
- API types generated from the backend's OpenAPI document with `openapi-typescript`; requests through `openapi-fetch`; query-option factories per feature key every query on `tenantId`.
- `date-fns` 4 with `@date-fns/tz` behind `lib/datetime.ts`; `lucide-react`; `sonner`; Recharts via shadcn `chart` (ADR-0002).
- Tooling: Biome 2 for lint and format; Vitest 5 (node + browser mode); Playwright for E2E (ADR-0016).

## Alternatives considered

- **Inertia + Laravel starter kit**: couples the SPA to Laravel rendering; the developer-platform story and a future separate admin app favour a pure API boundary. Rejected.
- **React Router 7**: no typed search params, team focus on Remix/full-stack. Rejected.
- **Next.js / TanStack Start (SSR)**: no SEO need; adds a Node runtime to the on-prem stack. Rejected.
- **React Hook Form**: fine, but v8 is in beta and needs a resolver package; TanStack Form is adapter-free with Zod 4. Rejected for coherence.
- **TypeScript 6 with typescript-eslint**: would block TS 7's speed; Biome removes the constraint. Rejected.

## Consequences

- Six concepts to learn (Router, Query, Table, Form, Tailwind v4 CSS-first theming, Base UI via shadcn); everything else is generated or transitive.
- The URL is the filter store for lists; views are shareable and back-button correct.
- A generated `routeTree.gen.ts` and `schema.d.ts` are committed; contract changes appear as diffs.
- Risk: Table v9 documentation gap (mitigated by the v8 fallback).

## Migration / future considerations

Storybook and a published shadcn registry when the design system is shared with a second app; TanStack Virtual for long threads; `ViewTransition`/`Activity` for polish; i18n by replacing `src/copy/en.ts` with a translation function.
