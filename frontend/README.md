# Smart Helpdesk — frontend

React 19 single-page application built with Vite 8 and TypeScript 7. Architecture: [../docs/03-architecture/frontend.md](../docs/03-architecture/frontend.md). Design system: [../docs/06-design-system/](../docs/06-design-system/tokens.md).

## Stack

TanStack Router (file routes in `src/routes`, generated `src/routeTree.gen.ts`), TanStack Query, Table and Form; Zod 4; Tailwind CSS 4; shadcn/ui on Base UI (`components.json`, style `base-nova`); lucide-react; date-fns with `@date-fns/tz`; Biome; Vitest 5 (Node and browser projects); Playwright.

## Layout

```text
src/
├── app/          bootstrap, router and query client
├── routes/       file routes only (thin)
├── features/     one folder per feature, imported through its index
├── components/   ui/ (shadcn, owned source) · shared/ · layout/
├── lib/          api client, auth, datetime, theme, runtime config, utils
├── copy/en.ts    every user-facing string
├── styles/       globals.css (Tailwind entry and tokens)
└── test/         test setup and MSW handlers
e2e/              Playwright tests
public/config.json  runtime configuration (replaced by the proxy per environment)
```

Import rules are enforced by Biome: `lib` imports nothing above it, `components` never import features or routes, and features import each other only through their index.

## Commands

```sh
pnpm install
pnpm dev            # http://localhost:5173 (through Caddy: https://app.shp.localhost)
pnpm build
pnpm typecheck      # tsc 7 --noEmit
pnpm lint           # biome ci
pnpm format         # biome check --write
pnpm test           # unit tests (Node)
pnpm test:browser   # component tests in headless Chromium
pnpm e2e            # Playwright against the Compose stack
pnpm api:types      # regenerate src/lib/api/schema.d.ts from ../backend/openapi.json
```

`pnpm exec playwright install chromium` is needed once for the browser and end-to-end tests.

## Notes

- `openapi-typescript` needs the TypeScript 5 compiler API, which TypeScript 7 does not provide yet, so `api:types` runs it through `pnpm dlx` with TypeScript 5.9.3 instead of installing it in the project.
- The React Compiler runs through `oxc-transform-react` (`react({ compiler: true })`), which plugin-react marks as experimental. Remove the option if it misbehaves.
