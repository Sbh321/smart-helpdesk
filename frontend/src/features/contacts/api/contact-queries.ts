import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import { type ApiListQuery, choiceFilter, defineListSchema, multiFilter } from '@/lib/list-params'

export type Contact = components['schemas']['ContactResource']
export type ContactInput = components['schemas']['ContactRequest']

type ContactIndex = operations['contacts.index']
export type ContactListQuery = NonNullable<ContactIndex['parameters']['query']>
export type ContactListPage = ContactIndex['responses'][200]['content']['application/json']

/** The contact list's sort allow-list and default (docs/07-api/pagination-filtering.md §Sorting). */
export const CONTACT_SORT_FIELDS = ['name', 'email', 'created_at', 'last_ticket_at'] as const

/** `filter[archived]`: absent means `false` (hide archived contacts). */
export const ARCHIVED_VALUES = ['false', 'true', 'all'] as const

/**
 * URL and API parameters of the contact list: `?organization_id=<uuid>,none&tag=<slug>&archived=all` in
 * the address bar is `filter[organization_id]=…&filter[tag]=…&filter[archived]=all` on the API call.
 */
export const contactListSchema = defineListSchema({
  sortFields: CONTACT_SORT_FIELDS,
  defaultSort: 'name',
  filters: {
    organization_id: multiFilter(z.union([z.uuid(), z.literal('none')])),
    tag: multiFilter(z.string().trim().min(1).max(100)),
    archived: choiceFilter(['true', 'all']),
  },
})

/** The organisation filter's "contacts without an organisation" value (`filter[organization_id]=none`). */
export const NO_ORGANIZATION = 'none'

export function listContacts(query: ApiListQuery): Promise<ContactListPage> {
  return unwrapBody(api().GET('/contacts', { params: { query: query as ContactListQuery } }))
}

export const contactQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.contacts.list(tenantId, query),
      queryFn: () => listContacts(query),
      placeholderData: keepPreviousData,
    }),
  detail: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.contacts.detail(tenantId, id),
      queryFn: () => unwrap(api().GET('/contacts/{contact}', { params: { path: { contact: id } } })),
    }),
  /** Fuzzy match on name and email, best first, at most 10 (`GET /v1/contacts/typeahead`). */
  typeahead: (tenantId: string, q: string) =>
    queryOptions({
      queryKey: queryKeys.contacts.typeahead(tenantId, q),
      queryFn: () => unwrap(api().GET('/contacts/typeahead', { params: { query: { q } } })),
      enabled: q.trim().length > 0,
      placeholderData: keepPreviousData,
      staleTime: 30_000,
    }),
}

export function createContact(input: ContactInput): Promise<Contact> {
  return unwrap(api().POST('/contacts', { body: input }))
}

export function updateContact(id: string, input: ContactInput): Promise<Contact> {
  return unwrap(api().PATCH('/contacts/{contact}', { params: { path: { contact: id } }, body: input }))
}

export function archiveContact(id: string): Promise<Contact> {
  return unwrap(api().POST('/contacts/{contact}/archive', { params: { path: { contact: id } } }))
}

export function unarchiveContact(id: string): Promise<Contact> {
  return unwrap(api().POST('/contacts/{contact}/unarchive', { params: { path: { contact: id } } }))
}
