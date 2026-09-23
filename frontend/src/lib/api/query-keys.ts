/**
 * TanStack Query key factory (docs/03-architecture/frontend.md). Every key starts with its domain so
 * `invalidateQueries({ queryKey: queryKeys.<domain>.all })` clears the whole domain.
 * Features add their domain here when their endpoints land.
 */
export const queryKeys = {
  notifications: {
    all: (tenantId: string) => ['notifications', tenantId] as const,
    list: (tenantId: string, query: object) =>
      [...queryKeys.notifications.all(tenantId), 'list', query] as const,
    unread: (tenantId: string) => [...queryKeys.notifications.all(tenantId), 'unread'] as const,
  },
  /** The session is central, not tenant-scoped: it is what tells us which tenant we are in. */
  session: {
    all: ['session'] as const,
    me: () => [...queryKeys.session.all, 'me'] as const,
  },
  /** Platform administration on the admin host (its own session, never tenant-scoped). */
  platform: {
    all: ['platform'] as const,
    me: () => [...queryKeys.platform.all, 'me'] as const,
    tenants: () => [...queryKeys.platform.all, 'tenants'] as const,
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
    comments: (tenantId: string, id: string) => [...queryKeys.tickets.all(tenantId), 'comments', id] as const,
    attachments: (tenantId: string, id: string) =>
      [...queryKeys.tickets.all(tenantId), 'attachments', id] as const,
    duplicates: (tenantId: string, id: string) =>
      [...queryKeys.tickets.all(tenantId), 'duplicates', id] as const,
    duplicatePreview: (tenantId: string, title: string, description: string) =>
      [...queryKeys.tickets.all(tenantId), 'duplicate-preview', title, description] as const,
  },
  sla: {
    all: (tenantId: string) => [tenantId, 'sla'] as const,
    ticket: (tenantId: string, ticketId: string) =>
      [...queryKeys.sla.all(tenantId), 'ticket', ticketId] as const,
    policies: (tenantId: string) => [...queryKeys.sla.all(tenantId), 'policies'] as const,
    calendars: (tenantId: string) => [...queryKeys.sla.all(tenantId), 'calendars'] as const,
  },
  assignment: {
    all: (tenantId: string) => [tenantId, 'assignment'] as const,
    candidates: (tenantId: string, ticketId: string) =>
      [...queryKeys.assignment.all(tenantId), 'candidates', ticketId] as const,
  },
  categories: {
    all: (tenantId: string) => [tenantId, 'categories'] as const,
  },
  agents: {
    all: (tenantId: string) => [tenantId, 'agents'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.agents.all(tenantId), 'list', query] as const,
    detail: (tenantId: string, id: string) => [...queryKeys.agents.all(tenantId), 'detail', id] as const,
    workload: (tenantId: string, id: string) =>
      [...queryKeys.agents.detail(tenantId, id), 'workload'] as const,
    shifts: (tenantId: string, id: string) => [...queryKeys.agents.detail(tenantId, id), 'shifts'] as const,
    availableUsers: (tenantId: string) => [...queryKeys.agents.all(tenantId), 'available-users'] as const,
  },
  skills: {
    all: (tenantId: string) => [tenantId, 'skills'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.skills.all(tenantId), 'list', query] as const,
  },
  teams: {
    all: (tenantId: string) => [tenantId, 'teams'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.teams.all(tenantId), 'list', query] as const,
  },
  media: {
    all: (tenantId: string) => [tenantId, 'media'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.media.all(tenantId), 'list', query] as const,
    folders: (tenantId: string) => [...queryKeys.media.all(tenantId), 'folders'] as const,
    usage: (tenantId: string) => [...queryKeys.media.all(tenantId), 'usage'] as const,
  },
  users: {
    all: (tenantId: string) => [tenantId, 'users'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.users.all(tenantId), 'list', query] as const,
  },
  roles: {
    all: (tenantId: string) => [tenantId, 'roles'] as const,
    list: (tenantId: string) => [...queryKeys.roles.all(tenantId), 'list'] as const,
    permissions: (tenantId: string) => [...queryKeys.roles.all(tenantId), 'permissions'] as const,
  },
  apiClients: {
    all: (tenantId: string) => [tenantId, 'api-clients'] as const,
    list: (tenantId: string) => [...queryKeys.apiClients.all(tenantId), 'list'] as const,
    scopes: (tenantId: string) => [...queryKeys.apiClients.all(tenantId), 'scopes'] as const,
  },
  webhooks: {
    all: (tenantId: string) => [tenantId, 'webhooks'] as const,
    list: (tenantId: string) => [...queryKeys.webhooks.all(tenantId), 'list'] as const,
    events: (tenantId: string) => [...queryKeys.webhooks.all(tenantId), 'events'] as const,
    deliveries: (tenantId: string, webhookId: string) =>
      [...queryKeys.webhooks.all(tenantId), 'deliveries', webhookId] as const,
  },
  reports: {
    all: (tenantId: string) => [tenantId, 'reports'] as const,
    catalogue: (tenantId: string) => [...queryKeys.reports.all(tenantId), 'catalogue'] as const,
    run: (tenantId: string, key: string, body: object) =>
      [...queryKeys.reports.all(tenantId), 'run', key, body] as const,
    records: (tenantId: string, key: string, query: object) =>
      [...queryKeys.reports.all(tenantId), 'records', key, query] as const,
    dashboard: (tenantId: string, period: string) =>
      [...queryKeys.reports.all(tenantId), 'dashboard', period] as const,
  },
  /** Report and ticket-list exports (M3-09): one queued file, polled until it is ready. */
  exports: {
    all: (tenantId: string) => [tenantId, 'exports'] as const,
    detail: (tenantId: string, id: string) => [...queryKeys.exports.all(tenantId), 'detail', id] as const,
  },
  /** Entity 360 (M3-21): overviews and the change history of any recorded record. */
  entity360: {
    all: (tenantId: string) => [tenantId, 'entity360'] as const,
    overview: (tenantId: string, entity: string, id: string) =>
      [...queryKeys.entity360.all(tenantId), 'overview', entity, id] as const,
    changes: (tenantId: string, type: string, id: string) =>
      [...queryKeys.entity360.all(tenantId), 'changes', type, id] as const,
    asOf: (tenantId: string, type: string, id: string, at: string) =>
      [...queryKeys.entity360.all(tenantId), 'as-of', type, id, at] as const,
  },
  /** The workspace audit log (M3-03): a cursor feed per filter set. */
  audit: {
    all: (tenantId: string) => [tenantId, 'audit'] as const,
    list: (tenantId: string, query: object) => [...queryKeys.audit.all(tenantId), 'list', query] as const,
  },
  /** The inbound email log of Settings → Email (M3-19): a cursor feed per filter set. */
  inboundEmails: {
    all: (tenantId: string) => [tenantId, 'inbound-emails'] as const,
    list: (tenantId: string, query: object) =>
      [...queryKeys.inboundEmails.all(tenantId), 'list', query] as const,
  },
  settings: {
    all: (tenantId: string) => [tenantId, 'settings'] as const,
    list: (tenantId: string) => [...queryKeys.settings.all(tenantId), 'list'] as const,
    section: (tenantId: string, section: string) =>
      [...queryKeys.settings.all(tenantId), 'section', section] as const,
  },
} as const
