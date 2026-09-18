# Frontend architecture

React 19 + TypeScript 7 + Vite 8 SPA. Decisions: [ADR-0001](../adr/0001-frontend-architecture.md), [ADR-0002](../adr/0002-ui-primitive-strategy.md), [ADR-0003](../adr/0003-state-management.md).

## Layout

```text
frontend/src/
├── app/                 main.tsx (Theme → Query → Router), router.tsx, query-client.ts
├── routes/              file-based routes (TanStack Router); thin: loader + component import
│   ├── __root.tsx       SessionProvider, Toaster, error and not-found components
│   ├── index.tsx        "/" — asks for a workspace, or forwards a signed-in visitor to their own
│   ├── $workspace.tsx   validates the slug shape; everything below lives in one workspace
│   ├── $workspace/_auth/          login, accept-invitation, reset-password (also "forgot")
│   ├── $workspace/_app/           authenticated shell (sidebar, topbar); the URL segment is
│   │   ├── index.tsx              display-only and checked against /v1/me
│   │   ├── tickets/     index.tsx (list + create dialog), $ticketId.tsx (M1-17 placeholder)
│   │   ├── contacts/    index.tsx new.tsx $contactId.tsx
│   │   ├── organizations/ index.tsx new.tsx $organizationId.tsx
│   │   ├── agents/      teams, skills, agents
│   │   ├── settings/    general, automation, sla, roles, users, integrations, developer
│   │   └── notifications.tsx
│   └── _platform.*      /platform/tenants (super admin, admin host only)
├── features/
│   ├── auth/            api/ (session query, pre-auth requests), components/ (forms),
│   │                    session-provider.tsx, auth-errors.ts, schemas.ts, index.ts
│   ├── tickets/         api/ticket-queries.ts, schemas.ts, components/ (TicketList, columns, CreateTicketDialog, screens)
│   ├── contacts/        contacts and organisations: api/ (contact-, organization-, tag-queries.ts), schemas.ts,
│   │                    components/ (lists, forms, screens, use-pickers.ts)
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
│   ├── shared/          EmptyState, ErrorState, PageHeader, StatusBadge, PriorityBadge, form fields, EntityCombobox, TagInput
│   │   └── data-table/  DataTable, its footer/column menu/keyboard hook, FilterBar and the filter primitives
│   └── layout/          AppShell, Sidebar, Topbar, CommandPalette
├── lib/
│   ├── api/             schema.d.ts (generated), client.ts (openapi-fetch), errors.ts (ApiError), query-keys.ts
│   ├── auth/            can.ts, guards.ts, session.ts, session-context.ts (useSession, useCan)
│   ├── datetime/        format, relative, TZDate helpers
│   ├── forms/           messages.ts (merges client and server field messages; serverFieldErrors for 422s)
│   ├── list-params/     defineListSchema (URL ⇄ API list parameters), useListParams
│   ├── theme/           ThemeProvider, useTheme, contrast (boot script is inline in index.html)
│   └── utils/
├── copy/en.ts           all user-facing strings
├── styles/              tokens.css, globals.css (Tailwind entry)
└── test/                setup, msw handlers (node + browser worker), render-app.tsx
```

Deviations settled while building the shell (M1-12):

- **The workspace segment covers the auth pages too.** `/{workspace}/login` rather than a top-level
  `_auth/login`, because the sign-in form needs a workspace and [ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md)
  puts it in the path. `_app` and `_auth` are pathless layouts *inside* one `$workspace` route, so there
  is a single `/$workspace` node and no ambiguity about which layout matches. `select-workspace` is the
  `/` route instead of a page of its own.
- **There is no `providers.tsx`.** Theme, Query and Router are composed in `main.tsx`; `SessionProvider`
  sits in `__root.tsx`, inside the router, so it can navigate (sign-out) and so guards and components
  share one `GET /v1/me` through the query cache.
- **`useSession` and `useCan` live in `lib/auth`, not in `features/auth`.** `components/layout` may not
  import features (the Biome boundary rule), and the shell needs both. `features/auth` owns the
  provider that fills the context; `lib/auth` owns the context, the permission check and the pure guard
  functions, which are therefore unit-testable without a router.
- Route guards are pure functions (`guardWorkspaceRoute`, `guardAuthRoute`, `afterSignInHref`) called
  from `beforeLoad`; a `?redirect=` value is only used when it is a same-origin path belonging to the
  signed-in workspace.

Import boundaries (enforced by Biome `noRestrictedImports` rules and review): `routes → features (index only) → components/shared,ui, lib`; `components/shared → components/ui, lib`; `lib` imports nothing above it; features never import other features except through `features/<x>/index.ts`.

## State by kind

| State | Home | Example |
|---|---|---|
| Server | TanStack Query, keys `[tenantId, 'tickets', 'list', params]` | ticket list, ticket detail, me |
| URL | Router search params with Zod schema per route (`defineListSchema` for lists) | `?status=open,assigned&priority=P1&sort=-priority_score&page=2` |
| Form | TanStack Form + Zod | ticket create/edit |
| Session | `SessionProvider` from `/v1/me`: user, tenant, permissions[], settings, unreadCount | `useCan('tickets.assign')` |
| Theme / density | `ThemeProvider`; `localStorage['sh.theme']` and `['sh.density']`; the inline boot script in `index.html` sets `data-theme`, `data-theme-choice` and `data-density` before paint | |
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

As built (M1-14) — API in [components.md §DataTable](../06-design-system/components.md#datatable):

- The table lives in `components/shared/data-table/` and knows nothing about the router: it takes the list state as props and reports changes through `onStateChange`. The router binding is `lib/list-params`: `defineListSchema({ sortFields, defaultSort, filters })` per endpoint, whose `searchSchema` is the route's `validateSearch`, and `useListParams(schema)` in the page, which reads the location and writes every change as a navigation. Pages pass `list.params` and `list.update` straight to the table.
- **URL names.** `page`, `per_page`, `sort` and `search` are the API's names. Filters use one flat key per filter — `?organization_id=<uuid>,none&tag=vip` — and `apiQuery` turns them into `filter[organization_id]=…&filter[tag]=…`, the flat keys the generated OpenAPI types use. The values are identical on both sides (comma lists, `YYYY-MM-DD,YYYY-MM-DD` ranges), so the mapping of [pagination-filtering.md](../07-api/pagination-filtering.md) stays one to one; only the `filter[…]` wrapper is dropped from the address bar, where the router would otherwise show `filter%5Btag%5D`.
- Defaults (page 1, 25 rows, the default sort) are left out of the URL, but the API call always carries the effective `sort`, so `aria-sort` and the server's order cannot disagree. Unrelated search params pass through untouched (`z.looseObject`).
- `manualFiltering` is not set: filters are not a table feature (no column filters are registered), they go straight from the URL into the query.
- The first real consumer is the contact list (`features/contacts`), built against MSW handlers that follow the contract (`src/test/msw/contacts.ts`) and checked against the real `GET /v1/contacts` once it landed. The organisation and ticket lists followed (M1-15, M1-17); the ticket list adds `include=contact,category` to the query and refetches every 30 s.
- The MSW layer is an in-memory workspace (`src/test/msw/data.ts`: 60 contacts, 3 organisations, 2 tags, 3 categories, 60 tickets) that the handlers read and mutate, validating like the API (sort and filter allow-lists, unique contact email, required fields → 422). `resetMockData()` runs after every test, next to `resetHandlers()`.

## Forms

TanStack Form with Zod schemas shared with route params where shapes coincide; shadcn `Field` components on Base UI; server validation errors (422 problem details) mapped back to fields by name; optimistic updates only for status/assignment toggles.

As built (M1-15, M1-17):

- Each feature keeps its Zod form schemas in `features/<x>/schemas.ts`; the form values are the UI's shape (a picked organisation is `{ value, label }`), converted to the API request in one `toInput()`.
- Validation runs on submit and, after a failed submit, on every change: `validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' })` with `validators: { onDynamic: schema }`. With a plain `onSubmit` validator, TanStack Form refuses the next submit while any field still carries the previous error, so a corrected field was never re-checked.
- A 422 goes through `serverFieldErrors()` (`lib/forms/messages.ts`; `tags.0` folds into `tags`, `organization_id` is shown on the organisation picker) and is merged per field with the client messages; any other failure shows `FormErrorBanner` with the request id. After a save the feature invalidates its domain's query prefix (`[tenantId, 'contacts']`) and puts the saved record into the detail query.
- Create and edit are routes (`contacts/new`, `contacts/$contactId`, the same for organisations) rather than dialogs, so they have URLs and the browser back button; the ticket create form is a `Dialog` on the list, as the roadmap asks for a "minimal create dialog". Pickers search the API as the user types, debounced by `useDebouncedValue` (250 ms).

## Runtime configuration

The SPA reads `/config.json` (served by Caddy from an env-templated file) at boot: `apiBaseUrl` (`https://api.shp.subhambhandari.com.np`; `/api` in single-host mode), `appMode` (`tenant` on `app`, `platform` on `admin`), `platformDomain`, `storagePublicEndpoint`, `realtime` (enabled, host, key). Nothing environment-specific is baked at build time, so one image serves every deployment.

## Error handling

`lib/api/client.ts` sends `credentials: 'include'` to the `api` host and converts problem-details responses into `ApiError { status, code, title, detail, errors? }`; a global `QueryCache.onError` shows a toast for unexpected errors; 401 redirects to login preserving `redirect`; 403 renders `ForbiddenState`; 404 renders `NotFoundState`; 422 is handled by forms. Details: [error-handling.md](error-handling.md).

As built (M1-12): a 401 from `GET /v1/me` is not an error but the answer "nobody is signed in", so the
session query resolves to `null` and the route guard — not an error handler — does the redirect. Every
other status still throws `ApiError`. The global `QueryCache.onError` toast arrives with the first route
that reads data that is not the session (M1-15).

## Accessibility

Base UI primitives provide focus management and ARIA; we add skip links, landmark regions, visible focus tokens, `aria-live` regions for toasts and SLA countdowns, reduced-motion media queries, and axe scans in E2E. [06-design-system/accessibility.md](../06-design-system/accessibility.md).

The axe scan also runs in the Vitest browser project (`src/test/accessibility.browser.test.tsx`), so a
regression fails in `pnpm test:browser` in seconds instead of only in the E2E run. Scans wait for
element animations to finish first: a half-faded dialog makes axe measure a blended colour and report a
contrast failure that no user would see.

## Build and quality

`pnpm dev` (Vite with proxy to `app`), `pnpm build` → `dist/` copied into the Caddy image; `pnpm typecheck` (`tsc --noEmit`), `pnpm lint` (Biome), `pnpm test` (Vitest), `pnpm test:browser`, `pnpm e2e` (Playwright), `pnpm api:types` (openapi-typescript from `backend/openapi.json`).

`package.json` declares `"sideEffects": ["*.css"]` (M1-14): the only import kept for its side effect is the stylesheet in `main.tsx`. Without the flag the bundler keeps every module a feature barrel re-exports, so the contacts route's `validateSearch: contactListSchema.searchSchema` pulled the whole contact list and TanStack Table into the entry chunk (605 kB, over Vite's warning limit); with it they stay in the lazily loaded route chunk (entry 367 kB, contacts 180 kB). A module that must run for its side effect has to be added to that list.
