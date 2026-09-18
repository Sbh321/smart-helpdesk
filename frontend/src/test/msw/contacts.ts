import { HttpResponse, http } from 'msw'
import { type ContactResource, db, NOW, nextId, type OrganizationResource, tagsByName } from './data'
import { apiUrl, problem } from './handlers'
import { paginate, sortRows, validateListQuery, validationFailed } from './list'

export { CONTACT_FIXTURES, fixtureId, ORGANIZATION_FIXTURES, TAG_FIXTURES } from './data'

/**
 * Contacts, organisations and tags as the backend implements them (docs/07-api/pagination-filtering.md,
 * docs/04-domain/contacts.md): `search` on name and email, `filter[organization_id]` (ids or `none`),
 * `filter[tag]` (slugs), `filter[archived]` (`false` by default, `true`, `all`); email unique per
 * workspace (422 on `errors.email`); unknown tag names are created on save.
 */
const CONTACT_SORTABLE = ['name', 'email', 'created_at', 'last_ticket_at'] as const
const CONTACT_FILTERS = ['organization_id', 'tag', 'archived'] as const
const ORGANIZATION_SORTABLE = ['name', 'created_at'] as const
const ORGANIZATION_FILTERS = ['tier', 'tag'] as const
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** The rows `GET /v1/contacts` would return for a query, before pagination. */
export function queryContacts(url: URL, contacts: ContactResource[] = db.contacts): ContactResource[] {
  let rows = [...contacts]
  const search = url.searchParams.get('search')?.trim().toLowerCase()
  if (search) {
    rows = rows.filter(
      (contact) =>
        contact.name.toLowerCase().includes(search) || contact.email.toLowerCase().includes(search),
    )
  }
  const organizations = url.searchParams.get('filter[organization_id]')?.split(',')
  if (organizations) {
    rows = rows.filter((contact) =>
      contact.organization ? organizations.includes(contact.organization.id) : organizations.includes('none'),
    )
  }
  const tags = url.searchParams.get('filter[tag]')?.split(',')
  if (tags) {
    rows = rows.filter((contact) => contact.tags.some((tag) => tags.includes(tag.slug)))
  }
  const archived = url.searchParams.get('filter[archived]') ?? 'false'
  if (archived !== 'all') {
    rows = rows.filter((contact) => (contact.archived_at !== null) === (archived === 'true'))
  }
  return sortRows(rows, url.searchParams.get('sort') ?? 'name')
}

export function contactsHandler(contacts?: ContactResource[]) {
  return http.get(apiUrl('/contacts'), ({ request }) => {
    const url = new URL(request.url)
    const invalid = validateListQuery(url, {
      sortable: CONTACT_SORTABLE,
      filters: CONTACT_FILTERS,
      defaultSort: 'name',
    })
    return invalid ?? HttpResponse.json(paginate(url, queryContacts(url, contacts ?? db.contacts)))
  })
}

type ContactBody = {
  name?: string
  email?: string
  phone?: string | null
  organization_id?: string | null
  tags?: string[]
}

function validateContact(body: ContactBody, selfId?: string): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  const name = body.name?.trim() ?? ''
  const email = body.email?.trim() ?? ''
  if (name === '') errors.name = ['The name field is required.']
  if (email === '') errors.email = ['The email field is required.']
  else if (!EMAIL.test(email)) errors.email = ['The email field must be a valid email address.']
  else if (db.contacts.some((c) => c.id !== selfId && c.email.toLowerCase() === email.toLowerCase())) {
    errors.email = ['The email has already been taken.']
  }
  if (body.organization_id && !db.organizations.some((o) => o.id === body.organization_id)) {
    errors.organization_id = ['The selected organization id is invalid.']
  }
  return errors
}

function applyContact(contact: ContactResource, body: ContactBody): ContactResource {
  const organization = db.organizations.find((o) => o.id === body.organization_id)
  return {
    ...contact,
    name: body.name?.trim() ?? contact.name,
    email: body.email?.trim() ?? contact.email,
    phone: body.phone === undefined ? contact.phone : body.phone,
    organization: organization
      ? { id: organization.id, name: organization.name, tier: organization.tier }
      : null,
    tags: body.tags ? tagsByName(body.tags) : contact.tags,
  }
}

function findContact(id: unknown): ContactResource | undefined {
  return db.contacts.find((contact) => contact.id === id)
}

const notFound = () => problem(404, 'not_found', { title: 'Not found' })

type OrganizationBody = { name?: string; domain?: string | null; tier?: string; tags?: string[] }

function validateOrganization(body: OrganizationBody): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if ((body.name?.trim() ?? '') === '') errors.name = ['The name field is required.']
  if (body.tier !== undefined && !['standard', 'premium', 'enterprise'].includes(body.tier)) {
    errors.tier = ['The selected tier is invalid.']
  }
  return errors
}

function applyOrganization(organization: OrganizationResource, body: OrganizationBody): OrganizationResource {
  return {
    ...organization,
    name: body.name?.trim() ?? organization.name,
    domain: body.domain === undefined ? organization.domain : body.domain || null,
    tier: (body.tier as OrganizationResource['tier'] | undefined) ?? organization.tier,
    tags: body.tags ? tagsByName(body.tags) : organization.tags,
  }
}

export const contactHandlers = [
  contactsHandler(),
  http.get(apiUrl('/contacts/typeahead'), ({ request }) => {
    const q = new URL(request.url).searchParams.get('q')?.trim().toLowerCase() ?? ''
    const rows = db.contacts
      .filter((c) => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q))
      .slice(0, 10)
    return HttpResponse.json({ data: rows })
  }),
  http.post(apiUrl('/contacts'), async ({ request }) => {
    const body = (await request.json()) as ContactBody
    const errors = validateContact(body)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const contact = applyContact(
      {
        id: nextId(1),
        name: '',
        email: '',
        phone: null,
        organization: null,
        tags: [],
        external_ids: {},
        metadata: {},
        last_ticket_at: null,
        archived_at: null,
        created_at: NOW,
      },
      body,
    )
    db.contacts.push(contact)
    return HttpResponse.json({ data: contact }, { status: 201 })
  }),
  http.get(apiUrl('/contacts/{contact}'), ({ params }) => {
    const contact = findContact(params.contact)
    return contact ? HttpResponse.json({ data: contact }) : notFound()
  }),
  http.patch(apiUrl('/contacts/{contact}'), async ({ params, request }) => {
    const contact = findContact(params.contact)
    if (!contact) return notFound()
    const body = (await request.json()) as ContactBody
    const errors = validateContact(body, contact.id)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const updated = applyContact(contact, body)
    db.contacts = db.contacts.map((c) => (c.id === contact.id ? updated : c))
    return HttpResponse.json({ data: updated })
  }),
  http.post(apiUrl('/contacts/{contact}/archive'), ({ params }) => {
    const contact = findContact(params.contact)
    if (!contact) return notFound()
    contact.archived_at = NOW
    return HttpResponse.json({ data: contact })
  }),
  http.post(apiUrl('/contacts/{contact}/unarchive'), ({ params }) => {
    const contact = findContact(params.contact)
    if (!contact) return notFound()
    contact.archived_at = null
    return HttpResponse.json({ data: contact })
  }),
  http.get(apiUrl('/organizations'), ({ request }) => {
    const url = new URL(request.url)
    const invalid = validateListQuery(url, {
      sortable: ORGANIZATION_SORTABLE,
      filters: ORGANIZATION_FILTERS,
      defaultSort: 'name',
    })
    if (invalid) return invalid
    let rows = [...db.organizations]
    const search = url.searchParams.get('search')?.trim().toLowerCase()
    if (search) {
      rows = rows.filter(
        (o) => o.name.toLowerCase().includes(search) || (o.domain ?? '').toLowerCase().includes(search),
      )
    }
    const tiers = url.searchParams.get('filter[tier]')?.split(',')
    if (tiers) rows = rows.filter((o) => tiers.includes(o.tier))
    return HttpResponse.json(paginate(url, sortRows(rows, url.searchParams.get('sort') ?? 'name')))
  }),
  http.post(apiUrl('/organizations'), async ({ request }) => {
    const body = (await request.json()) as OrganizationBody
    const errors = validateOrganization(body)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const organization = applyOrganization(
      {
        id: nextId(2),
        name: '',
        domain: null,
        tier: 'standard',
        external_ids: {},
        metadata: {},
        contacts_count: 0,
        tags: [],
        created_at: NOW,
      },
      body,
    )
    db.organizations.push(organization)
    return HttpResponse.json({ data: organization }, { status: 201 })
  }),
  http.get(apiUrl('/organizations/{organization}'), ({ params }) => {
    const organization = db.organizations.find((o) => o.id === params.organization)
    return organization ? HttpResponse.json({ data: organization }) : notFound()
  }),
  http.patch(apiUrl('/organizations/{organization}'), async ({ params, request }) => {
    const organization = db.organizations.find((o) => o.id === params.organization)
    if (!organization) return notFound()
    const body = (await request.json()) as OrganizationBody
    const errors = validateOrganization(body)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const updated = applyOrganization(organization, body)
    db.organizations = db.organizations.map((o) => (o.id === organization.id ? updated : o))
    return HttpResponse.json({ data: updated })
  }),
  http.get(apiUrl('/tags'), ({ request }) => {
    const search = new URL(request.url).searchParams.get('search')?.trim().toLowerCase()
    const rows = search ? db.tags.filter((tag) => tag.name.toLowerCase().includes(search)) : db.tags
    return HttpResponse.json({ data: rows })
  }),
]
