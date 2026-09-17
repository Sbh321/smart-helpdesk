# Frontend architecture

React 19 + TypeScript 7 + Vite 8 SPA. Decisions: [ADR-0001](../adr/0001-frontend-architecture.md), [ADR-0002](../adr/0002-ui-primitive-strategy.md), [ADR-0003](../adr/0003-state-management.md).

## Layout

```text
frontend/src/
├── app/                 main.tsx, providers.tsx (Query, Router, Theme, Session), router.ts
├── routes/              file-based routes (TanStack Router); thin: loader + component import
│   ├── __root.tsx
│   ├── _auth/           login (workspace + email + password), accept-invitation, reset-password, select-workspace
│   ├── $workspace/      authenticated shell (sidebar, topbar); URL segment is display-only and checked against /v1/me
│   │   ├── dashboard.tsx
│   │   ├── tickets/     index.tsx ($ticketId.tsx) new.tsx
│   │   ├── contacts/    index.tsx $contactId.tsx
│   │   ├── agents/      teams, skills, agents
│   │   ├── settings/    general, automation, sla, roles, users, integrations, developer
│   │   └── notifications.tsx
│   └── _platform/       tenants (super admin, central host only)
├── features/
│   ├── auth/            api/, components/, hooks/useSession.ts, schemas/
│   ├── tickets/         api/ticketQueries.ts, components/TicketTable, TicketForm, TicketTimeline, PriorityExplanation ...
│   ├── contacts/
│   ├── agents/
│   ├── sla/
│   ├── automation/      settings forms with live preview
│   ├── dashboard/
│   ├── integrations/
│   ├── notifications/
│   ├── reports/         report catalogue, report runner, charts, entity 360, history timeline, exports
│   ├── media/
│   └── platform/
├── components/
│   ├── ui/              shadcn-generated (owned source)
│   ├── shared/          DataTable, EmptyState, ErrorState, PageHeader, StatusBadge, PriorityBadge, SlaBadge, ConfirmDialog
│   └── layout/          AppShell, Sidebar, Topbar, CommandPalette
├── lib/
│   ├── api/             schema.d.ts (generated), client.ts (openapi-fetch + problem-details handling), queryKeys.ts
│   ├── auth/            can.ts, session.ts
│   ├── datetime/        format, relative, TZDate helpers
│   ├── theme/           ThemeProvider, useTheme, boot script
│   └── utils/
├── copy/en.ts           all user-facing strings
├── styles/              tokens.css, globals.css (Tailwind entry)
└── test/                setup, msw handlers
```

Import boundaries (enforced by Biome `noRestrictedImports` rules and review): `routes → features (index only) → components/shared,ui, lib`; `components/shared → components/ui, lib`; `lib` imports nothing above it; features never import other features except through `features/<x>/index.ts`.

## State by kind

| State | Home | Example |
|---|---|---|
| Server | TanStack Query, keys `[tenantId, 'tickets', 'list', params]` | ticket list, ticket detail, me |
| URL | Router search params with Zod schema per route | `?status=open,assigned&priority=P1&sort=-priority_score&page=2` |
| Form | TanStack Form + Zod | ticket create/edit |
| Session | `SessionProvider` from `/v1/me`: user, tenant, permissions[], settings, unreadCount | `useCan('tickets.assign')` |
| Theme / density | `ThemeProvider`; `localStorage['sh.theme']`; boot script sets `data-theme` before paint | |
| Feature flags | `tenant.settings.features` in session | `features.realtime` |
| Ephemeral UI | `useState` / provider | dialogs, sidebar collapsed (persisted in localStorage) |

## Data access pattern

```ts
// features/tickets/api/ticketQueries.ts
export const ticketQueries = {
  list: (tenantId: string, params: TicketListParams) => queryOptions({
    queryKey: [tenantId, 'tickets', 'list', params],
    queryFn: () => api.GET('/v1/tickets', { params: { query: params } }).then(unwrap),
    placeholderData: keepPreviousData,
    refetchInterval: 30_000,
  }),
  detail: (tenantId: string, id: string) => queryOptions({ ... refetchInterval: 10_000 }),
}
```

Routes call `queryClient.ensureQueryData(ticketQueries.list(...))` in `loader` using validated search params; mutations invalidate by prefix `[tenantId, 'tickets']`. When Reverb is enabled, `useEcho` listeners call the same invalidations.

## Tables

`components/shared/DataTable` wraps TanStack Table v9 in **server mode** (`manualPagination`, `manualSorting`, `manualFiltering`, `rowCount`), reads state from and writes to route search params, supports column visibility (localStorage), row selection with bulk-action bar, sticky header, keyboard row navigation, loading skeleton, empty and error states. Page sizes 25/50/100; no virtualisation in the MVP.

## Forms

TanStack Form with Zod schemas shared with route params where shapes coincide; shadcn `Field` components on Base UI; server validation errors (422 problem details) mapped back to fields by name; optimistic updates only for status/assignment toggles.

## Runtime configuration

The SPA reads `/config.json` (served by Caddy from an env-templated file) at boot: `apiBaseUrl` (`https://api.shp.subhambhandari.com.np`; `/api` in single-host mode), `appMode` (`tenant` on `app`, `platform` on `admin`), `platformDomain`, `storagePublicEndpoint`, `realtime` (enabled, host, key). Nothing environment-specific is baked at build time, so one image serves every deployment.

## Error handling

`lib/api/client.ts` sends `credentials: 'include'` to the `api` host and converts problem-details responses into `ApiError { status, code, title, detail, errors? }`; a global `QueryCache.onError` shows a toast for unexpected errors; 401 redirects to login preserving `redirect`; 403 renders `ForbiddenState`; 404 renders `NotFoundState`; 422 is handled by forms. Details: [error-handling.md](error-handling.md).

## Accessibility

Base UI primitives provide focus management and ARIA; we add skip links, landmark regions, visible focus tokens, `aria-live` regions for toasts and SLA countdowns, reduced-motion media queries, and axe scans in E2E. [06-design-system/accessibility.md](../06-design-system/accessibility.md).

## Build and quality

`pnpm dev` (Vite with proxy to `app`), `pnpm build` → `dist/` copied into the Caddy image; `pnpm typecheck` (`tsc --noEmit`), `pnpm lint` (Biome), `pnpm test` (Vitest), `pnpm test:browser`, `pnpm e2e` (Playwright), `pnpm api:types` (openapi-typescript from `backend/openapi.json`).
