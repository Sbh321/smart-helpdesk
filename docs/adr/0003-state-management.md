# ADR-0003 State management: server cache, URL and context; no global store

**Status:** Accepted (2026-09-17)

## Context

The brief lists TanStack Store, Zustand and others and asks to avoid storing everything globally. Analysis of the actual state in a helpdesk SPA shows almost no client-only cross-tree state.

## Decision

| Kind of state | Home |
|---|---|
| Server data (tickets, contacts, users, settings, notifications) | TanStack Query cache; keys prefixed with `tenantId` |
| List filters, sort, page, selected row IDs, active tab | TanStack Router search params validated by Zod |
| Form state | TanStack Form, local to the form |
| Authenticated user, tenant, permissions, tenant settings | `SessionProvider` context populated by the `/v1/me` query; `useCan(permission)` hook |
| Theme (light/dark/system), density | `ThemeProvider` context + `localStorage`; resolved at boot before first paint |
| Feature flags | Part of the `/me` payload (tenant settings); read via context |
| Dialogs, sidebar, command palette | Local component state or the owning provider |

**No global store library in the MVP.** TanStack Store remains only as Table v9's internal dependency.

## Alternatives considered

Zustand 5 (mature, 1 kB) — add only for a proven cross-tree need such as a draft buffer that must survive navigation. Jotai 3.0 — nine days old. TanStack Store — 0.x, not an app store. Redux Toolkit — ceremony without need.

## Consequences

Fewer concepts; filter state is shareable via URL; permission checks are one hook; a Zustand slice can be added later without refactoring because nothing else pretends to be global.

## Migration / future considerations

Realtime (ADR-0009) uses Query invalidation, so enabling Reverb changes no state architecture. Offline/local-first (TanStack DB) is not planned.
