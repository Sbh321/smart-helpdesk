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
│   ├── users/           Settings → Users and Roles: api/user-queries.ts, schemas.ts, components/
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
- Forms with rows or nested request keys (SLA targets, weekly windows, shifts, priority weights) use `useServerErrors()` (`lib/forms/use-server-errors.ts`): `fields` holds the folded 422 messages, `exact` the API's own keys (`targets.2.resolution_minutes`) for the row's control, `failure` anything else for the `FormErrorBanner`. Number inputs keep their text in the form (`integerText`/`decimalText` in `lib/forms/numbers.ts`) and become numbers in `toInput()`, so an emptied input is a validation message, not `0`.
- Stable problem codes the SPA words itself live in `lib/api/problem-messages.ts` with their copy under `copy.problems` (`resolution_comment_required` goes to the comment field; `invalid_transition` is refined by `meta.reason`; `already_decided` and `already_assigned` refetch and show an info toast instead of an error; `no_eligible_agent` lists `meta.exclusions`). `FormErrorBanner` uses that wording before the problem detail. A 429 on the advisory duplicate preview is dropped silently, without a retry.
- Actions that cannot be undone go through `ConfirmDialog` (`components/shared/confirm-dialog.tsx`, on Base UI `AlertDialog`): focus starts on Cancel, both buttons are disabled while `onConfirm` runs, and a rejection is shown inside the dialog.
- Settings screens keep one file per entity in their feature (`features/agents/components/{skill,team,category,agent,shift}-settings.tsx`); routes import a feature's `index.ts` only.
- Create and edit are routes (`contacts/new`, `contacts/$contactId`, the same for organisations) rather than dialogs, so they have URLs and the browser back button; the ticket create form is a `Dialog` on the list, as the roadmap asks for a "minimal create dialog". Pickers search the API as the user types, debounced by `useDebouncedValue` (250 ms).

M2-06 adds `tickets/new`, reusing the list dialog's form, with inline contact creation and a
redirect to the created ticket. Ticket detail has a dialog for editable fields and explicit
transition actions from `TicketResource.allowed_transitions`; the API owns state-machine and
permission guards. A transition cancels the detail query, snapshots it, optimistically changes
status, restores the snapshot on failure, then invalidates ticket queries. Ticket history now
uses an infinite query over `meta.next_cursor`, removing the first-50-events shortcut. Detail
polls every ten seconds. All request/response shapes come from the generated OpenAPI schema.

M2-02 adds nested Settings routes for the Agent directory (Skills, Teams, Categories, Agents,
Shifts). Skills, Teams and Agents use the shared server-mode DataTable with URL-bound search,
sort and pagination; Categories uses the complete catalogue. The Topbar availability control uses an optimistic session update and restores the
previous value on a failed PATCH. The shift editor keeps a draft of the complete server schedule
and disables replacement until the selected Agent's schedule has loaded.

M2-10's Ticket create form debounces duplicate preview requests by 350 ms after title and
description input. The Ticket Duplicates tab reads stored suggestions through a tenant-keyed query;
marking or dismissing one invalidates the relevant Ticket queries. This flow is still awaiting
the deferred Week 2 browser and API gate.

M2-01 adds `features/settings`: one screen per workspace settings section (General, Branding,
Automation, Tickets; SLA defaults and shift enforcement sit on the SLA and Shifts pages; priority
weights save from the priority page). `SettingsSectionScreen` loads `GET /v1/settings/{section}` and
types it with the section's Zod schema (`readSection`); number forms share `NumberSettingsForm`, on/off
settings save on switch through `SettingSwitch`. A PATCH sends only the section and invalidates the
section, the list and `/me`. 422 `validation_failed` and `settings_invalid` both land on fields through
`useServerErrors` (`server.exact['baseline.weights']` for a group). Branding previews the colour live
through `ThemeProvider.previewTenantPrimary` and reverts it when the page is left unsaved; the saved
colour from `/me` becomes `<style id="tenant-brand">` (`lib/theme/brand.ts`, `applyTenantBrand`) and
the logo `TenantLogo` in the sidebar. Workspace administrators land on General when they open Settings.

M2-09 adds `features/notifications`: `NotificationBell` (unread count polled every 30 s from
`GET /v1/notifications?filter[unread]=true&per_page=1`, a popover with the latest ten, mark read, mark
all read, "View all") and the `/$workspace/notifications` page (unread filter, pages of 25). The shell
may not import features, so the route passes the bell into `Topbar` through the `notifications` slot,
as it passes the availability control through `actions`. A rise of the count after the first reading
is announced in a polite live region. MVP-SHORTCUT: polling; V1: realtime on the user's private channel.

M2-11 completes the ticket list. `ticketListSchema` now carries every API filter the list offers —
status, priority, `assignee_id` (Agent ids, `unassigned`, `me`), `team_id` (ids, `none`), category, tag
slug, organisation, `sla_state`, `has_duplicate_suggestion=true` (a `choiceFilter`) and the created range —
and the `sla_due_at` sort, so each one round-trips `?assignee_id=me&status=active` ⇄
`filter[assignee_id]=me&filter[status]=active`. Assignee and Team options come from the Agent directory
(`useDirectoryNames`, which now also returns the lists; `agents.view`), tags and organisations from the
contacts feature's option queries. Quick views (All, My tickets, Unassigned, Breaching soon) are presets of
the same URL parameters; the pressed one is derived from the URL (`activeQuickView`), and any other
combination reads "Custom filters". "My tickets" needs an Agent profile in `/me`. Assignee, Team and SLA due
are columns (Team and SLA due hidden by default through the DataTable's new `defaultColumnVisibility`);
MVP-SHORTCUT: `TicketResource` carries no due time, so SLA due sorts but shows a dash; V1: add
`sla_due_at`/`sla_state` to the resource. Bulk actions use the DataTable's new controlled `selection`, so
a selection spans pages: "Change status" (`tickets.update`; resolved and closed only with
`tickets.resolve`/`tickets.close`, resolved requires a comment as in the single-ticket dialog) and "Assign"
(`tickets.assign`: an Agent, a Team, both, or auto-assign). `runInChunks` sends at most 100 ids per request,
one request after the other, with a progress bar; a later chunk that fails as a whole becomes failed rows
with its problem code, a failure of the first is shown in the dialog. The results dialog lives outside the
bulk bar (which disappears with the selection), counts successes, lists every failed ticket by number and
title with `bulkRowMessage` (`problem-messages.ts`: our wording for the code, then the API detail) and offers
"Select failed" to retry them; ticket queries are invalidated after every run.

M2-12 adds `features/users`: Settings → Users (`users.manage`) and Settings → Roles (`roles.manage`).
Users is a server-mode DataTable over `GET /v1/users` with URL-bound search, sort, a status multi-select
and a role filter (`?status=invited&role=agent`); the status badge shows "Invitation expired" from the
API's `invitation_expired`, and last sign-in is shown in the workspace time zone. Invite and edit are
dialogs whose roles are a checkbox group fed by `GET /v1/roles`, or by the five default role names when
the signed-in user lacks `roles.manage`. A 403 `forbidden` with `meta.roles` (the owner role, or a role
or permission beyond the actor's reach) is shown beside the roles or permissions, not as a banner;
422 `last_owner` and 409 `conflict` (`self`) are worded in `problem-messages.ts`. Resend invitation
appears only for invited users, Disable goes through `ConfirmDialog` and is not offered on the
signed-in user's own row. Roles lists the global defaults read-only and custom roles with create, edit
and delete; the editor groups `GET /v1/permissions` by resource with a per-group "select all"
(indeterminate when partly selected), labels come from `copy.roles.permissionLabels` with a readable
fallback, and a 409 `in_use` on delete says how many users still hold the role. Saving a user's own
roles or a custom role also invalidates `/me`.

M3-01 and M3-20 add `features/reports` (the dashboard lives there too rather than in a `features/dashboard`,
because it is a fixed selection of the same reports and shares their charts and formatting). Recharts 3.10
arrives through shadcn `chart`. `/$workspace` is the dashboard: `?period=` in the URL (`dashboardSearchSchema`),
one `GET /v1/dashboard` query, eight `KpiTile`s with the change against the previous period and six
`ChartCard`s whose table alternative is always in the page; every tile and chart links to its report with the
same period. Without `reports.view` the dashboard says so instead of querying. `/$workspace/reports` lists
the catalogue by group; `/$workspace/reports/$reportKey` is one report: the definition comes from the
cached catalogue, the URL state is `report-params.ts` (`reportSearchSchema` is the route's `validateSearch`
and keeps unknown keys, because the filter keys depend on the definition; `parseReportSearch` /
`toReportSearch` / `toRunBody` / `toRecordsQuery` convert, unit-tested), the `ParameterBar` reuses the
`FilterBar` primitives and the other features' option queries (`useDirectoryNames`, `categoryQueries`,
`organizationQueries.options`, `slaQueries.policies`, fixed lists in `copy.reports.fixedLabels`), and each
query runs only when the report has such a filter and the caller may read it. The run is
`POST /v1/reports/{key}/run` with `keepPreviousData`; the data table is the shared `DataTable` given one
page of the sorted rows (the table itself stays server-mode; `ReportTable` holds page and sort locally),
and drill-down is a `Dialog` bound to `?drill=` so back closes it. `chart-choice.ts` maps the declared
chart and the dimension to what is drawn (reporting.md §Report page behaviour). Values are formatted by
unit in `features/reports/format.ts`. The Reports navigation entry needs `reports.view`. The print
stylesheet (`globals.css`, `print:hidden` on the sidebar, topbar, parameter bar and buttons) prints the
tables in full. MSW: `src/test/msw/reports.ts` (catalogue of ten real definitions exported from the
backend, deterministic runs, records, dashboard) records the run bodies and records queries for tests.
The series chart chunk is about 410 kB (Recharts) and is loaded only by the dashboard and report routes.

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
