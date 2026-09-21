import { useQuery } from '@tanstack/react-query'
import type { FilterOption } from '@/components/shared/data-table'
import { copy } from '@/copy/en'
import { categoryQueries, useDirectoryNames } from '@/features/agents'
import { organizationQueries } from '@/features/contacts'
import { slaQueries } from '@/features/sla'
import { useCan, useSession } from '@/lib/auth'
import type { ReportDefinition } from '../api/report-queries'

export interface FilterChoices {
  options: FilterOption[]
  isLoading: boolean
}

const NO_OPTIONS: FilterOption[] = []
/** `none` matches an empty value (no team, no category), as on the API. */
const NONE: FilterOption = { value: 'none', label: copy.reports.none }

function fixed(lookup: string): FilterOption[] | undefined {
  const labels = copy.reports.fixedLabels[lookup]
  return labels ? Object.entries(labels).map(([value, label]) => ({ value, label })) : undefined
}

/**
 * Options for a report's filters, by the lookup each filter names (`labels`): the existing option
 * queries of the other features (Agent directory, categories, organisations, SLA policies) and the fixed
 * lists. A query runs only when the report has a filter that needs it and the caller may read it; a
 * filter without options is not offered.
 */
export function useFilterOptions(definition: ReportDefinition | undefined): Record<string, FilterChoices> {
  const tenantId = useSession().session?.tenant.id ?? ''
  const lookups = new Set((definition?.filters ?? []).map((filter) => filter.labels ?? ''))
  const canTickets = useCan('tickets.view')
  const canContacts = useCan('contacts.view')
  const directory = useDirectoryNames(lookups.has('agents') || lookups.has('teams'))
  const categories = useQuery({
    ...categoryQueries.all(tenantId),
    enabled: tenantId !== '' && canTickets && lookups.has('categories'),
  })
  const organizations = useQuery({
    ...organizationQueries.options(tenantId),
    enabled: tenantId !== '' && canContacts && lookups.has('organizations'),
  })
  const policies = useQuery({
    ...slaQueries.policies(tenantId),
    enabled: tenantId !== '' && canTickets && lookups.has('sla_policies'),
  })

  const byLookup = (lookup: string | null): FilterChoices => {
    switch (lookup) {
      case 'agents':
        return {
          options: [NONE, ...directory.agents.map((agent) => ({ value: agent.id, label: agent.user.name }))],
          isLoading: directory.isLoading,
        }
      case 'teams':
        return {
          options: [NONE, ...directory.teams.map((team) => ({ value: team.id, label: team.name }))],
          isLoading: directory.isLoading,
        }
      case 'categories':
        return {
          options: [
            NONE,
            ...(categories.data ?? []).map((category) => ({ value: category.id, label: category.name })),
          ],
          isLoading: categories.isLoading,
        }
      case 'organizations':
        return { options: [NONE, ...(organizations.data ?? [])], isLoading: organizations.isLoading }
      case 'sla_policies':
        return {
          options: (policies.data ?? []).map((policy) => ({ value: policy.id, label: policy.name })),
          isLoading: policies.isLoading,
        }
      default:
        return { options: (lookup ? fixed(lookup) : undefined) ?? NO_OPTIONS, isLoading: false }
    }
  }

  return Object.fromEntries(
    (definition?.filters ?? []).map((filter) => [filter.key, byLookup(filter.labels)]),
  )
}
