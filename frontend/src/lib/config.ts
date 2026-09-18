import { z } from 'zod'

/** Runtime configuration served as /config.json by the proxy, so one build runs on every host (docs/03-architecture/frontend.md). */
export const runtimeConfigSchema = z.object({
  apiBaseUrl: z.url(),
  appMode: z.enum(['tenant', 'platform']),
  platformDomain: z.string().min(1),
  storagePublicEndpoint: z.url(),
  realtime: z.object({
    enabled: z.boolean(),
    host: z.string().optional(),
    key: z.string().optional(),
  }),
})

export type RuntimeConfig = z.infer<typeof runtimeConfigSchema>

export async function loadRuntimeConfig(fetcher: typeof fetch = fetch): Promise<RuntimeConfig> {
  const response = await fetcher('/config.json', { cache: 'no-store' })
  if (!response.ok) {
    throw new Error(`Could not load /config.json (${response.status})`)
  }
  return runtimeConfigSchema.parse(await response.json())
}
