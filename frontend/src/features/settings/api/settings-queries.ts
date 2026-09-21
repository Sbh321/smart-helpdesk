import { type QueryClient, queryOptions, useQuery } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'
import { useCan, useSession } from '@/lib/auth'

export type SettingsSection = components['schemas']['SettingsSectionResource']

/** The sections of `GET /v1/settings` (docs/03-architecture/configuration.md). */
export type SettingsSectionKey =
  | 'general'
  | 'branding'
  | 'automation.priority'
  | 'automation.assignment'
  | 'automation.duplicates'
  | 'tickets'
  | 'sla'
  | 'shifts'
  | 'features'

export const settingsQueries = {
  list: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.settings.list(tenantId),
      queryFn: () => unwrap(api().GET('/settings')),
    }),
  section: (tenantId: string, section: SettingsSectionKey) =>
    queryOptions({
      queryKey: queryKeys.settings.section(tenantId, section),
      queryFn: () => unwrap(api().GET('/settings/{section}', { params: { path: { section } } })),
    }),
}

/**
 * `PATCH /v1/settings/{section}`: a partial body, deep-merged by the API. The generated operation has no
 * request body (the controller reads free-form JSON), hence the cast.
 */
export function patchSettings(section: SettingsSectionKey, input: object): Promise<SettingsSection> {
  return unwrap(api().PATCH('/settings/{section}', { params: { path: { section } }, body: input as never }))
}

/**
 * Saves a section, stores the answer in its query and refreshes what depends on it: the section list
 * and the session (`me.tenant` carries the name, time zone, branding and feature flags).
 */
export async function saveSettings(
  client: QueryClient,
  tenantId: string,
  section: SettingsSectionKey,
  input: object,
): Promise<SettingsSection> {
  const saved = await patchSettings(section, input)
  client.setQueryData(queryKeys.settings.section(tenantId, section), saved)
  await Promise.all([
    client.invalidateQueries({ queryKey: queryKeys.settings.all(tenantId) }),
    client.invalidateQueries({ queryKey: queryKeys.session.me() }),
  ])
  return saved
}

/** One section for a settings screen; only asked for with `settings.manage`. */
export function useSettingsSection(section: SettingsSectionKey) {
  const allowed = useCan('settings.manage')
  const tenantId = useSession().session?.tenant.id ?? ''
  const query = useQuery({
    ...settingsQueries.section(tenantId, section),
    enabled: allowed && tenantId !== '',
  })
  return { allowed, tenantId, query }
}
