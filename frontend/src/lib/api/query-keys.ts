/**
 * TanStack Query key factory (docs/03-architecture/frontend.md). Every key starts with its domain so
 * `invalidateQueries({ queryKey: queryKeys.<domain>.all })` clears the whole domain.
 * Features add their domain here when their endpoints land.
 */
export const queryKeys = {
  /** The session is central, not tenant-scoped: it is what tells us which tenant we are in. */
  session: {
    all: ['session'] as const,
    me: () => [...queryKeys.session.all, 'me'] as const,
  },
  system: {
    all: ['system'] as const,
    ping: () => [...queryKeys.system.all, 'ping'] as const,
  },
  /** Tenant data starts with the tenant id (docs/03-architecture/frontend.md §State by kind). */
  contacts: {
    all: (tenantId: string) => [tenantId, 'contacts'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.contacts.all(tenantId), 'list', query] as const,
    detail: (tenantId: string, id: string) => [...queryKeys.contacts.all(tenantId), 'detail', id] as const,
    typeahead: (tenantId: string, q: string) =>
      [...queryKeys.contacts.all(tenantId), 'typeahead', q] as const,
  },
  organizations: {
    all: (tenantId: string) => [tenantId, 'organizations'] as const,
    list: (tenantId: string, query: object) =>
      [...queryKeys.organizations.all(tenantId), 'list', query] as const,
    detail: (tenantId: string, id: string) =>
      [...queryKeys.organizations.all(tenantId), 'detail', id] as const,
    options: (tenantId: string) => [...queryKeys.organizations.all(tenantId), 'options'] as const,
    search: (tenantId: string, q: string) => [...queryKeys.organizations.all(tenantId), 'search', q] as const,
  },
  tags: {
    all: (tenantId: string) => [tenantId, 'tags'] as const,
    options: (tenantId: string) => [...queryKeys.tags.all(tenantId), 'options'] as const,
    search: (tenantId: string, q: string) => [...queryKeys.tags.all(tenantId), 'search', q] as const,
  },
  tickets: {
    all: (tenantId: string) => [tenantId, 'tickets'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.tickets.all(tenantId), 'list', query] as const,
    detail: (tenantId: string, id: string) => [...queryKeys.tickets.all(tenantId), 'detail', id] as const,
    history: (tenantId: string, id: string) => [...queryKeys.tickets.all(tenantId), 'history', id] as const,
  },
  categories: {
    all: (tenantId: string) => [tenantId, 'categories'] as const,
  },
} as const
