import type { components } from '@/lib/api/schema'

/**
 * The in-memory workspace the MSW handlers serve and mutate. Fixtures are deterministic; `resetMockData`
 * (called after every browser test) restores them, so one test's "create" never leaks into the next.
 */
export type ContactResource = components['schemas']['ContactResource']
export type OrganizationResource = components['schemas']['OrganizationResource']
export type TagResource = components['schemas']['TagResource']
export type TicketResource = components['schemas']['TicketResource']
export type CategoryResource = components['schemas']['CategoryResource']
export type TicketEventResource = components['schemas']['TicketEventResource']
export type SkillResource = components['schemas']['SkillResource']
export type TeamResource = components['schemas']['TeamResource']
export type AgentResource = components['schemas']['AgentResource']
export type AgentShiftResource = components['schemas']['AgentShiftResource']
export type SlaTimerResource = components['schemas']['TicketSlaTimerResource']
export type SlaPolicyResource = components['schemas']['SlaPolicyResource']
export type CalendarResource = components['schemas']['BusinessCalendarResource']
export type CommentResource = components['schemas']['TicketCommentResource']
export type DuplicateSuggestionResource = components['schemas']['DuplicateSuggestionResource']
export type MediaItemResource = components['schemas']['MediaItemResource']
export type MediaFolderResource = components['schemas']['MediaFolderResource']
export type SettingsSectionResource = components['schemas']['SettingsSectionResource']
export type NotificationResource = components['schemas']['NotificationResource']
export type WorkspaceUserResource = components['schemas']['WorkspaceUserResource']
export type RoleResource = components['schemas']['RoleResource']
export type ApiClientResource = components['schemas']['ApiClientResource']

/** UUID v7-shaped ids, deterministic so tests can refer to them. */
export function fixtureId(kind: number, index: number): string {
  const hex = index.toString(16).padStart(12, '0')
  return `019a${kind.toString(16).padStart(4, '0')}-0000-7000-8000-${hex}`
}

export const TAG_FIXTURES: TagResource[] = [
  { id: fixtureId(3, 1), name: 'VIP', slug: 'vip', color: null },
  { id: fixtureId(3, 2), name: 'Beta tester', slug: 'beta-tester', color: null },
]

export const ORGANIZATION_FIXTURES: OrganizationResource[] = [
  ['Acme Corporation', 'enterprise', 'acme.example'],
  ['Globex', 'premium', null],
  ['Initech', 'standard', 'initech.example'],
].map(([name, tier, domain], index) => ({
  id: fixtureId(2, index + 1),
  name: name as string,
  domain: domain as string | null,
  tier: tier as OrganizationResource['tier'],
  external_ids: {},
  metadata: {},
  contacts_count: 20,
  tags: index === 0 && TAG_FIXTURES[0] ? [TAG_FIXTURES[0]] : [],
  created_at: `2026-09-0${index + 1}T08:00:00Z`,
}))

const FIRST_NAMES = ['Aarav', 'Bina', 'Chandra', 'Deepa', 'Esha', 'Farhan', 'Gita', 'Hari', 'Isha', 'Jiban']
const LAST_NAMES = ['Adhikari', 'Bhandari', 'Chaudhary', 'Dahal', 'Gurung', 'Karki']

/** 60 contacts: every third has no organisation, every fourth is VIP, every fifth a beta tester. */
export const CONTACT_FIXTURES: ContactResource[] = Array.from({ length: 60 }, (_, index) => {
  const first = FIRST_NAMES[index % FIRST_NAMES.length] ?? 'Anon'
  const last = LAST_NAMES[Math.floor(index / FIRST_NAMES.length) % LAST_NAMES.length] ?? 'Contact'
  const organization = index % 3 === 2 ? undefined : ORGANIZATION_FIXTURES[index % 3]
  const tags = [
    ...(index % 4 === 0 && TAG_FIXTURES[0] ? [TAG_FIXTURES[0]] : []),
    ...(index % 5 === 0 && TAG_FIXTURES[1] ? [TAG_FIXTURES[1]] : []),
  ]
  const day = String((index % 28) + 1).padStart(2, '0')
  return {
    id: fixtureId(1, index + 1),
    name: `${first} ${last}`,
    email: `${first}.${last}.${index + 1}@example.test`.toLowerCase(),
    phone: index % 2 === 0 ? `+977-98${String(index).padStart(8, '0')}` : null,
    organization: organization
      ? { id: organization.id, name: organization.name, tier: organization.tier }
      : null,
    tags,
    external_ids: {},
    metadata: {},
    last_ticket_at: index % 6 === 0 ? null : `2026-09-${day}T10:00:00Z`,
    archived_at: null,
    created_at: `2026-08-${day}T0${index % 10}:00:00Z`,
  }
})

export const SKILL_FIXTURES: SkillResource[] = ['Billing', 'Networking', 'Accounts'].map((name, index) => ({
  id: fixtureId(6, index + 1),
  name,
  slug: name.toLowerCase(),
  description: null,
}))

export const AGENT_FIXTURES: AgentResource[] = ['Asha Rai', 'Bikram Shah', 'Chen Gurung'].map(
  (name, index) => ({
    id: fixtureId(7, index + 1),
    user: {
      id: fixtureId(8, index + 1),
      name,
      email: `${name.toLowerCase().replace(' ', '.')}@acme.test`,
      is_active: true,
    },
    capacity: 10,
    availability: index === 1 ? 'away' : 'available',
    active_ticket_count: index + 1,
    last_assigned_at: null,
    skills: index === 2 || !SKILL_FIXTURES[0] ? [] : [{ ...SKILL_FIXTURES[0], level: 3 + index }],
    teams: [],
  }),
)

export const TEAM_FIXTURES: TeamResource[] = ['Accounts', 'Technical'].map((name, index) => ({
  id: fixtureId(9, index + 1),
  name,
  description: null,
  members: AGENT_FIXTURES[index]
    ? [
        {
          id: AGENT_FIXTURES[index].id,
          user_id: AGENT_FIXTURES[index].user.id,
          name: AGENT_FIXTURES[index].user.name,
          email: AGENT_FIXTURES[index].user.email,
          availability: AGENT_FIXTURES[index].availability,
          capacity: AGENT_FIXTURES[index].capacity,
        },
      ]
    : [],
}))

for (const [index, agent] of AGENT_FIXTURES.entries()) {
  const team = TEAM_FIXTURES[index % TEAM_FIXTURES.length]
  if (team) agent.teams = [{ id: team.id, name: team.name }]
}

export const CATEGORY_FIXTURES: CategoryResource[] = ['Billing', 'Technical', 'Account'].map(
  (name, index) => ({
    id: fixtureId(4, index + 1),
    name,
    default_team: TEAM_FIXTURES[index]
      ? { id: TEAM_FIXTURES[index].id, name: TEAM_FIXTURES[index].name }
      : null,
    required_skills: SKILL_FIXTURES[index] ? [SKILL_FIXTURES[index]] : [],
    is_active: true,
    sort_order: index + 1,
  }),
)

const STATUSES = ['open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed'] as const
const LEVELS = ['P1', 'P2', 'P3', 'P4'] as const
const TITLES = ['Cannot sign in', 'Invoice is wrong', 'Password reset email missing', 'Export times out']

/**
 * 60 tickets, numbered 1001–1060; statuses cycle through all six, levels through P1–P4. Open tickets have
 * no Agent and no Team; every other ticket has Agent `index % 3` and that Agent's Team. Every fifth ticket
 * (from the second) is tagged VIP.
 */
export const TICKET_FIXTURES: TicketResource[] = Array.from({ length: 60 }, (_, index) => {
  const contact = CONTACT_FIXTURES[index % CONTACT_FIXTURES.length] as ContactResource
  const category = CATEGORY_FIXTURES[index % CATEGORY_FIXTURES.length] as CategoryResource
  const level = LEVELS[index % LEVELS.length] ?? 'P4'
  const day = String((index % 18) + 1).padStart(2, '0')
  const status = STATUSES[index % STATUSES.length] ?? 'open'
  const agent = status === 'open' ? undefined : AGENT_FIXTURES[index % AGENT_FIXTURES.length]
  return {
    id: fixtureId(5, index + 1),
    number: 1001 + index,
    title: `${TITLES[index % TITLES.length]} (${index + 1})`,
    description: 'Reported through the portal.',
    status,
    impact: (index % 4) + 1,
    urgency: ((index + 1) % 4) + 1,
    priority_score: 100 - index,
    priority_level: level,
    priority_computed_level: level,
    priority_overridden: false,
    priority_explanation: {},
    priority_override_reason: null,
    allowed_transitions: [],
    contact_id: contact.id,
    organization_id: contact.organization?.id ?? null,
    category_id: category.id,
    team_id: agent?.teams[0]?.id ?? null,
    assigned_agent_id: agent?.id ?? null,
    duplicate_of_id: null,
    created_via: 'api',
    tags: index % 5 === 1 && TAG_FIXTURES[0] ? [TAG_FIXTURES[0]] : [],
    resolved_at: null,
    closed_at: null,
    created_at: `2026-09-${day}T0${index % 10}:00:00Z`,
    updated_at: `2026-09-${day}T1${index % 10}:00:00Z`,
  }
})

export type TicketSlaState = 'running' | 'warning' | 'breached' | 'paused' | 'met'

/**
 * The latest resolution timer per ticket, as the list's `filter[sla_state]` and `sort=sla_due_at` read it.
 * Active tickets cycle through running, warning, breached and paused, due a few hours after creation
 * (the due time falls with the ticket number within each state); resolved tickets are `met`; closed
 * tickets have no timer.
 */
export const TICKET_SLA_FIXTURES: Record<string, { state: TicketSlaState; due_at: string | null }> =
  Object.fromEntries(
    TICKET_FIXTURES.flatMap((ticket, index): [string, { state: TicketSlaState; due_at: string | null }][] => {
      if (ticket.status === 'closed') return []
      if (ticket.status === 'resolved') return [[ticket.id, { state: 'met', due_at: null }]]
      const states = ['running', 'warning', 'breached', 'paused'] as const
      const state = states[index % states.length] ?? 'running'
      const due = new Date(Date.parse('2026-09-19T00:00:00Z') + (60 - index) * 3_600_000)
      return [[ticket.id, { state, due_at: due.toISOString() }]]
    }),
  )

/** Tickets with a pending duplicate suggestion (`filter[has_duplicate_suggestion]=true`). */
export const DUPLICATE_SUGGESTED_FIXTURES: string[] = TICKET_FIXTURES.filter(
  (_ticket, index) => index % 7 === 2,
).map((ticket) => ticket.id)

export const CALENDAR_FIXTURES: CalendarResource[] = [
  {
    id: fixtureId(10, 1),
    name: 'Kathmandu office',
    timezone: 'Asia/Kathmandu',
    weekly_hours: { mon: [['10:00', '17:00']], tue: [['10:00', '17:00']] },
    is_default: true,
    holidays: [{ id: fixtureId(11, 1), date: '2026-10-20', name: 'Dashain', recurs_yearly: false }],
  },
]

export const SLA_POLICY_FIXTURES: SlaPolicyResource[] = [
  {
    id: fixtureId(12, 1),
    name: 'Standard support',
    is_default: true,
    applies_to_tier: null,
    warning_fraction: 0.8,
    calendar_id: fixtureId(10, 1),
    version: 1,
    targets: [
      { priority_level: 'P1', first_response_minutes: 30, resolution_minutes: 240 },
      { priority_level: 'P2', first_response_minutes: 60, resolution_minutes: 480 },
      { priority_level: 'P3', first_response_minutes: 240, resolution_minutes: 1440 },
      { priority_level: 'P4', first_response_minutes: 480, resolution_minutes: 2880 },
    ],
  },
]

export const MEDIA_FOLDER_FIXTURES: MediaFolderResource[] = [
  { id: fixtureId(13, 1), parent_id: null, name: 'Tickets', system_key: 'tickets' },
  { id: fixtureId(13, 2), parent_id: null, name: 'Brand', system_key: null },
  { id: fixtureId(13, 3), parent_id: fixtureId(13, 2), name: 'Logos', system_key: null },
].map((folder) => ({ ...folder, created_at: '2026-09-01T08:00:00Z', updated_at: '2026-09-01T08:00:00Z' }))

/** 30 media items: every third is in "Logos", the last two are in the trash, the first is used by a ticket. */
export const MEDIA_FIXTURES: MediaItemResource[] = Array.from({ length: 30 }, (_, index) => {
  const image = index % 2 === 0
  const day = String((index % 28) + 1).padStart(2, '0')
  return {
    id: fixtureId(14, index + 1),
    folder_id: index % 3 === 0 ? fixtureId(13, 3) : null,
    name: image ? `screenshot-${index + 1}.png` : `report-${index + 1}.pdf`,
    mime_type: image ? 'image/png' : 'application/pdf',
    size_bytes: 2048 * (index + 1),
    width: image ? 800 : null,
    height: image ? 600 : null,
    checksum_sha256: null,
    variants: { thumb: null, preview: null },
    variants_skipped: null,
    source: 'upload',
    state: index >= 28 ? 'trashed' : 'ready',
    uploaded_by_user_id: null,
    used_in_count: index === 0 ? 1 : 0,
    used_in_tickets: index === 0 ? [{ number: 1003 }] : [],
    tags: [],
    trashed_at: index >= 28 ? '2026-09-17T08:00:00Z' : null,
    completed_at: `2026-09-${day}T08:00:00Z`,
    created_at: `2026-09-${day}T08:00:00Z`,
  }
})

/** The code defaults of every settings section (`config/helpdesk.php`); `general` comes from the tenant. */
export const SETTINGS_DEFAULTS: Record<string, Record<string, unknown>> = {
  general: { name: 'Acme', timezone: 'Asia/Kathmandu' },
  branding: { primary: null, logo_media_id: null, logo_dark_media_id: null },
  'automation.priority': {
    baseline: {
      weights: { impact: 0.4, urgency: 0.35, tier: 0.15, age: 0.1 },
      thresholds: { P1: 75, P2: 50, P3: 25 },
      age_full_hours: 72,
    },
  },
  'automation.assignment': { enabled: true },
  'automation.duplicates': {
    baseline: { threshold: 0.35, candidate_limit: 50, window_days: 30, max_suggestions: 5 },
  },
  tickets: { auto_close_days: 7, reopen_window_days: 14 },
  sla: { warning_fraction: 0.75, first_response_applies_to_agent_created: true },
  shifts: { enforce: false },
  features: { realtime: false, exports: true },
}

/** `GET /v1/permissions`: the permission catalogue, resource → actions (PermissionCatalogue::PERMISSIONS). */
export const PERMISSION_CATALOGUE: Record<string, string[]> = {
  tickets: ['view', 'create', 'update', 'assign', 'resolve', 'close', 'reopen', 'delete'],
  comments: ['internal'],
  contacts: ['view', 'manage'],
  agents: ['view', 'manage'],
  teams: ['manage'],
  sla: ['manage'],
  calendars: ['manage'],
  shifts: ['manage'],
  media: ['view', 'upload', 'manage'],
  mail: ['manage'],
  settings: ['manage'],
  users: ['manage'],
  roles: ['manage'],
  integrations: ['manage'],
  reports: ['view'],
  audit: ['view'],
}

const ALL_PERMISSIONS = Object.entries(PERMISSION_CATALOGUE).flatMap(([resource, actions]) =>
  actions.map((action) => `${resource}.${action}`),
)
const AGENT_PERMISSIONS = [
  'tickets.view',
  'tickets.create',
  'tickets.update',
  'tickets.resolve',
  'comments.internal',
  'contacts.view',
  'agents.view',
  'media.view',
  'media.upload',
]

/** The five global defaults first, then the workspace's custom roles (`GET /v1/roles`). */
export const ROLE_FIXTURES: RoleResource[] = [
  { name: 'owner', permissions: ALL_PERMISSIONS },
  { name: 'admin', permissions: ALL_PERMISSIONS },
  {
    name: 'manager',
    permissions: [...AGENT_PERMISSIONS, 'tickets.assign', 'tickets.close', 'tickets.reopen', 'reports.view'],
  },
  { name: 'agent', permissions: AGENT_PERMISSIONS },
  { name: 'developer', permissions: ['tickets.view', 'integrations.manage'] },
]
  .map(({ name, permissions }, index) => ({
    id: fixtureId(51, index + 1),
    name,
    is_global: true,
    is_system: true,
    permissions,
  }))
  .concat([
    {
      id: fixtureId(51, 0x10),
      name: 'billing-lead',
      is_global: false,
      is_system: false,
      permissions: ['tickets.view', 'tickets.update'],
    },
    {
      id: fixtureId(51, 0x11),
      name: 'night-shift',
      is_global: false,
      is_system: false,
      permissions: ['tickets.view'],
    },
  ])

/** The id of the signed-in user of `sessionFixture()`: an owner in the users list. */
export const SESSION_USER_ID = '01a0b0a3-80b4-732e-9aaf-a1a943537bc9'

const user = (
  index: number,
  name: string,
  status: WorkspaceUserResource['status'],
  roles: string[],
  extra: Partial<WorkspaceUserResource> = {},
): WorkspaceUserResource => ({
  id: fixtureId(50, index),
  name,
  email: `${name.split(' ')[0]?.toLowerCase()}@acme.test`,
  status,
  roles,
  invitation_expires_at: null,
  invitation_expired: false,
  last_login_at: status === 'active' ? `2026-09-1${index}T06:30:00Z` : null,
  created_at: `2026-09-0${index}T08:00:00Z`,
  ...extra,
})

/** Workspace users: the signed-in owner, two active users, two invitations (one expired), one disabled. */
export const USER_FIXTURES: WorkspaceUserResource[] = [
  user(1, 'Priya Sharma', 'active', ['owner'], { id: SESSION_USER_ID, email: 'priya@acme.test' }),
  user(2, 'Asha Rai', 'active', ['agent', 'billing-lead']),
  user(3, 'Bikram Shah', 'active', ['admin', 'manager']),
  user(4, 'Nima Lama', 'invited', ['agent'], {
    invitation_expires_at: '2026-09-20T09:00:00Z',
  }),
  user(5, 'Kiran Thapa', 'invited', ['manager'], {
    invitation_expires_at: '2026-09-10T09:00:00Z',
    invitation_expired: true,
  }),
  user(6, 'Dipesh Karki', 'disabled', ['agent']),
]

/** One active and one revoked API client (Settings → Developer). */
export const API_CLIENT_FIXTURES: ApiClientResource[] = [
  {
    id: '01a0c290-0000-7000-8000-00000000a001',
    name: 'Monitoring',
    scopes: ['tickets:read', 'tickets:write'],
    revoked: false,
    revoked_at: null,
    last_used_at: '2026-09-18T08:30:00Z',
    created_at: '2026-09-10T09:00:00Z',
  },
  {
    id: '01a0c290-0000-7000-8000-00000000a002',
    name: 'Old CRM sync',
    scopes: ['contacts:write'],
    revoked: true,
    revoked_at: '2026-09-12T09:00:00Z',
    last_used_at: null,
    created_at: '2026-09-01T09:00:00Z',
  },
]

function clone<T>(value: T): T {
  return structuredClone(value)
}

/** Three notifications of the signed-in user: two unread, newest first as the API sorts them. */
export const NOTIFICATION_FIXTURES: NotificationResource[] = [
  ['ticket_assigned', 0, '2026-09-21T08:00:00Z', null],
  ['sla_warning', 1, '2026-09-21T07:30:00Z', null],
  ['internal_note', 2, '2026-09-20T16:00:00Z', '2026-09-20T17:00:00Z'],
].map(([kind, index, createdAt, readAt]) => {
  const ticket = TICKET_FIXTURES[Number(index)]
  if (!ticket) throw new Error('Missing ticket fixture')
  return {
    id: fixtureId(40, Number(index) + 1),
    kind: kind as NotificationResource['kind'],
    ticket_id: ticket.id,
    ticket_number: ticket.number,
    ticket_title: ticket.title,
    export_id: null,
    media_id: null,
    file_name: null,
    inbound_email_id: null,
    summary: 'Summary from the API',
    read_at: readAt === null ? null : String(readAt),
    created_at: String(createdAt),
  }
})

export const db = {
  contacts: clone(CONTACT_FIXTURES),
  organizations: clone(ORGANIZATION_FIXTURES),
  tags: clone(TAG_FIXTURES),
  tickets: clone(TICKET_FIXTURES),
  ticketSla: clone(TICKET_SLA_FIXTURES),
  duplicateSuggested: clone(DUPLICATE_SUGGESTED_FIXTURES),
  categories: clone(CATEGORY_FIXTURES),
  skills: clone(SKILL_FIXTURES),
  teams: clone(TEAM_FIXTURES),
  agents: clone(AGENT_FIXTURES),
  agentShifts: {} as Record<string, AgentShiftResource[]>,
  ticketEvents: {} as Record<string, TicketEventResource[]>,
  slaTimers: {} as Record<string, SlaTimerResource[]>,
  slaPolicies: clone(SLA_POLICY_FIXTURES),
  calendars: clone(CALENDAR_FIXTURES),
  comments: {} as Record<string, CommentResource[]>,
  duplicates: {} as Record<string, DuplicateSuggestionResource[]>,
  /** The latest assignment row per ticket, as `GET /tickets/{ticket}/assignment` returns it (M4-06). */
  assignments: {} as Record<string, components['schemas']['TicketAssignmentResource']>,
  ticketAttachments: {} as Record<string, string[]>,
  media: clone(MEDIA_FIXTURES),
  mediaFolders: clone(MEDIA_FOLDER_FIXTURES),
  settings: clone(SETTINGS_DEFAULTS),
  settingsVersion: 1,
  notifications: clone(NOTIFICATION_FIXTURES),
  users: clone(USER_FIXTURES),
  roles: clone(ROLE_FIXTURES),
  apiClients: clone(API_CLIENT_FIXTURES),
  sequence: 0,
}

export function resetMockData(): void {
  db.contacts = clone(CONTACT_FIXTURES)
  db.organizations = clone(ORGANIZATION_FIXTURES)
  db.tags = clone(TAG_FIXTURES)
  db.tickets = clone(TICKET_FIXTURES)
  db.ticketSla = clone(TICKET_SLA_FIXTURES)
  db.duplicateSuggested = clone(DUPLICATE_SUGGESTED_FIXTURES)
  db.categories = clone(CATEGORY_FIXTURES)
  db.skills = clone(SKILL_FIXTURES)
  db.teams = clone(TEAM_FIXTURES)
  db.agents = clone(AGENT_FIXTURES)
  db.agentShifts = {}
  db.ticketEvents = {}
  db.slaTimers = {}
  db.slaPolicies = clone(SLA_POLICY_FIXTURES)
  db.calendars = clone(CALENDAR_FIXTURES)
  db.comments = {}
  db.duplicates = {}
  db.assignments = {}
  db.ticketAttachments = {}
  db.media = clone(MEDIA_FIXTURES)
  db.mediaFolders = clone(MEDIA_FOLDER_FIXTURES)
  db.settings = clone(SETTINGS_DEFAULTS)
  db.settingsVersion = 1
  db.notifications = clone(NOTIFICATION_FIXTURES)
  db.users = clone(USER_FIXTURES)
  db.roles = clone(ROLE_FIXTURES)
  db.apiClients = clone(API_CLIENT_FIXTURES)
  db.sequence = 0
}

/** A fresh id for a record created during a test. */
export function nextId(kind: number): string {
  db.sequence += 1
  return fixtureId(kind, 0x100000 + db.sequence)
}

/** Tags by name, created when unknown — as the API does on save. */
export function tagsByName(names: readonly string[]): TagResource[] {
  return names.map((name) => {
    const existing = db.tags.find((tag) => tag.name.toLowerCase() === name.toLowerCase())
    if (existing) return existing
    const tag = { id: nextId(3), name, slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-'), color: null }
    db.tags.push(tag)
    return tag
  })
}

export const NOW = '2026-09-18T09:00:00Z'
