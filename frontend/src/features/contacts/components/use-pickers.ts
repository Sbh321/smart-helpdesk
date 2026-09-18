import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import type { EntityOption } from '@/components/shared/entity-combobox'
import { useSession } from '@/lib/auth'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import { contactQueries } from '../api/contact-queries'
import { organizationQueries } from '../api/organization-queries'
import { tagQueries } from '../api/tag-queries'

const NO_NAMES: string[] = []
const NO_OPTIONS: EntityOption[] = []

export function useTenantId(): string {
  return useSession().session?.tenant.id ?? ''
}

/** Tag names for the tag input, searched as the user types (`GET /v1/tags?search=`). */
export function useTagSuggestions() {
  const tenantId = useTenantId()
  const [query, setQuery] = useState('')
  const debounced = useDebouncedValue(query)
  const result = useQuery({ ...tagQueries.search(tenantId, debounced), enabled: tenantId !== '' })
  return { suggestions: result.data ?? NO_NAMES, setQuery }
}

/** Organisations for the contact form's picker (`GET /v1/organizations?search=`). */
export function useOrganizationOptions() {
  const tenantId = useTenantId()
  const [query, setQuery] = useState('')
  const debounced = useDebouncedValue(query)
  const result = useQuery({ ...organizationQueries.search(tenantId, debounced), enabled: tenantId !== '' })
  const options = result.data?.map((organization) => ({ value: organization.id, label: organization.name }))
  return { options: options ?? NO_OPTIONS, setQuery, isLoading: result.isFetching }
}

/** Contacts for the ticket dialog's picker (`GET /v1/contacts/typeahead?q=`). */
export function useContactOptions() {
  const tenantId = useTenantId()
  const [query, setQuery] = useState('')
  const debounced = useDebouncedValue(query)
  const result = useQuery({
    ...contactQueries.typeahead(tenantId, debounced),
    enabled: tenantId !== '' && debounced.trim() !== '',
  })
  const options = result.data?.map((contact) => ({
    value: contact.id,
    label: contact.name,
    detail: contact.email,
  }))
  return {
    options: debounced.trim() === '' ? NO_OPTIONS : (options ?? NO_OPTIONS),
    setQuery,
    isLoading: result.isFetching,
  }
}
