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

export const CATEGORY_FIXTURES: CategoryResource[] = ['Billing', 'Technical', 'Account'].map(
  (name, index) => ({
    id: fixtureId(4, index + 1),
    name,
    is_active: true,
    sort_order: index + 1,
  }),
)

const STATUSES = ['open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed'] as const
const LEVELS = ['P1', 'P2', 'P3', 'P4'] as const
const TITLES = ['Cannot sign in', 'Invoice is wrong', 'Password reset email missing', 'Export times out']

/** 60 tickets, numbered 1001–1060; statuses cycle through all six, levels through P1–P4. */
export const TICKET_FIXTURES: TicketResource[] = Array.from({ length: 60 }, (_, index) => {
  const contact = CONTACT_FIXTURES[index % CONTACT_FIXTURES.length] as ContactResource
  const category = CATEGORY_FIXTURES[index % CATEGORY_FIXTURES.length] as CategoryResource
  const level = LEVELS[index % LEVELS.length] ?? 'P4'
  const day = String((index % 18) + 1).padStart(2, '0')
  return {
    id: fixtureId(5, index + 1),
    number: 1001 + index,
    title: `${TITLES[index % TITLES.length]} (${index + 1})`,
    description: 'Reported through the portal.',
    status: STATUSES[index % STATUSES.length] ?? 'open',
    impact: (index % 4) + 1,
    urgency: ((index + 1) % 4) + 1,
    priority_score: 100 - index,
    priority_level: level,
    priority_computed_level: level,
    priority_overridden: false,
    contact_id: contact.id,
    organization_id: contact.organization?.id ?? null,
    category_id: category.id,
    team_id: null,
    assigned_agent_id: null,
    duplicate_of_id: null,
    created_via: 'api',
    resolved_at: null,
    closed_at: null,
    created_at: `2026-09-${day}T0${index % 10}:00:00Z`,
    updated_at: `2026-09-${day}T1${index % 10}:00:00Z`,
  }
})

function clone<T>(value: T): T {
  return structuredClone(value)
}

export const db = {
  contacts: clone(CONTACT_FIXTURES),
  organizations: clone(ORGANIZATION_FIXTURES),
  tags: clone(TAG_FIXTURES),
  tickets: clone(TICKET_FIXTURES),
  categories: clone(CATEGORY_FIXTURES),
  sequence: 0,
}

export function resetMockData(): void {
  db.contacts = clone(CONTACT_FIXTURES)
  db.organizations = clone(ORGANIZATION_FIXTURES)
  db.tags = clone(TAG_FIXTURES)
  db.tickets = clone(TICKET_FIXTURES)
  db.categories = clone(CATEGORY_FIXTURES)
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
