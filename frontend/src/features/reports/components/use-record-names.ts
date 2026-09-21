import { useQuery } from '@tanstack/react-query'
import { categoryQueries, useDirectoryNames } from '@/features/agents'
import { organizationQueries } from '@/features/contacts'
import { useCan, useSession } from '@/lib/auth'
import type { NameLookup } from '../entity-history'

/**
 * Names for the ids a history or overview shows, from the option queries the other features already
 * cache (Agent directory, categories, the first organisations). Each runs only when the reader may read
 * it; an id they do not cover is shown as a short id.
 */
export function useRecordNames(enabled = true): NameLookup {
  const tenantId = useSession().session?.tenant.id ?? ''
  const canTickets = useCan('tickets.view')
  const canContacts = useCan('contacts.view')
  const directory = useDirectoryNames(enabled)
  const categories = useQuery({
    ...categoryQueries.all(tenantId),
    enabled: enabled && tenantId !== '' && canTickets,
  })
  const organizations = useQuery({
    ...organizationQueries.options(tenantId),
    enabled: enabled && tenantId !== '' && canContacts,
  })
  return {
    organization: (id) => organizations.data?.find((option) => option.value === id)?.label,
    category: (id) => categories.data?.find((category) => category.id === id)?.name,
    team: directory.teamName,
    agent: directory.agentName,
    user: (id) => directory.agents.find((agent) => agent.user.id === id)?.user.name,
  }
}
