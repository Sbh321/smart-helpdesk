import { useRouteContext } from '@tanstack/react-router'
import { z } from 'zod'

/** Runtime configuration served as /config.json by the proxy, so one build runs on every host (docs/03-architecture/frontend.md). */
export const runtimeConfigSchema = z.object({
  apiBaseUrl: z.url(),
  appMode: z.enum(['tenant', 'platform']),
  platformDomain: z.string().min(1),
  storagePublicEndpoint: z.url(),
  /** The API reference (M5-01): `docs.<domain>`, or `<domain>/docs` in single-host mode. */
  docsUrl: z.url(),
  /** The platform documentation (M5-06), for platform super admins only. */
  platformDocsUrl: z.url(),
  /** Reverb (M3-16): the public app key, the WebSocket host and a path prefix (`/api` in single-host mode). */
  realtime: z.object({
    enabled: z.boolean(),
    host: z.string().optional(),
    key: z.string().optional(),
    path: z.string().optional(),
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

/** The runtime configuration from the router context, for components outside a route's own tree. */
export function useRuntimeConfig(): RuntimeConfig {
  return useRouteContext({ from: '__root__' }).config
}
