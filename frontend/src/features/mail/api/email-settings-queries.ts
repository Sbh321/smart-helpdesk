import { type QueryClient, queryOptions, useQuery } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'
import { useCan, useSession } from '@/lib/auth'

export type EmailSettings = components['schemas']['EmailSettingsResource']
export type DnsRecord = components['schemas']['DnsRecordResource']

/** Under the settings keys, so saving any section refreshes it too (the version is shared). */
const key = (tenantId: string) => queryKeys.settings.section(tenantId, 'email')

export const emailSettingsQueries = {
  show: (tenantId: string) =>
    queryOptions({ queryKey: key(tenantId), queryFn: () => unwrap(api().GET('/settings/email')) }),
}

/** `GET /v1/settings/email`; only asked for with `mail.manage` (docs/04-domain/email.md §Settings → Email). */
export function useEmailSettings() {
  const allowed = useCan('mail.manage')
  const tenantId = useSession().session?.tenant.id ?? ''
  const query = useQuery({ ...emailSettingsQueries.show(tenantId), enabled: allowed && tenantId !== '' })
  return { allowed, tenantId, query }
}

/**
 * `PATCH /v1/settings/email` with only the fields to change: null or empty restores the default sender
 * name; the two inbound switches (M3-19) are booleans.
 */
export async function saveEmailSettings(
  client: QueryClient,
  tenantId: string,
  input: { sender_name?: string | null; create_contacts?: boolean; match_organisation_domain?: boolean },
): Promise<EmailSettings> {
  const saved = await unwrap(api().PATCH('/settings/email', { body: input }))
  client.setQueryData(key(tenantId), saved)
  await client.invalidateQueries({ queryKey: queryKeys.settings.list(tenantId) })
  return saved
}
