import type { EchoOptions } from 'laravel-echo'
import type { ChannelAuthorizationHandler } from 'pusher-js'
import { apiBaseUrl, readCookie, XSRF_COOKIE, XSRF_HEADER } from '@/lib/api/client'
import type { RuntimeConfig } from '@/lib/config'

export type RealtimeEchoOptions = EchoOptions<'reverb'>

/**
 * Channel authorisation through `POST /v1/broadcasting/auth` with the session cookie and the Sanctum
 * XSRF header, like every other API call (the default Pusher authorizer sends neither cross-origin).
 */
export function channelAuthorizer(
  endpoint: string,
  fetcher: typeof fetch = (input, init) => globalThis.fetch(input, init),
  readCookies: () => string = () => (typeof document === 'undefined' ? '' : document.cookie),
): ChannelAuthorizationHandler {
  return ({ socketId, channelName }, callback) => {
    const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' }
    const token = readCookie(readCookies(), XSRF_COOKIE)
    if (token) {
      headers[XSRF_HEADER] = token
    }
    fetcher(endpoint, {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
    })
      .then(async (response) => {
        if (!response.ok) {
          throw new Error(`Channel authorisation failed (${response.status})`)
        }
        callback(null, await response.json())
      })
      .catch((error: unknown) => {
        callback(error instanceof Error ? error : new Error(String(error)), null)
      })
  }
}

let testOverride: Partial<RealtimeEchoOptions> | null = null

/** Browser tests swap the Pusher connector for a fake (src/test/fake-echo.ts). */
export function overrideEchoOptionsForTests(options: Partial<RealtimeEchoOptions> | null): void {
  testOverride = options
}

/**
 * Echo options from the runtime config.json, or `null` when this deployment has no Reverb. The socket
 * goes to `wss://<host><path>/app/<key>` on 443 through the proxy (docs/09-infrastructure/docker.md).
 */
export function realtimeEchoOptions(config: RuntimeConfig): RealtimeEchoOptions | null {
  const { enabled, key, host, path } = config.realtime
  if (!enabled || !key || !host) {
    return null
  }
  return {
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: 443,
    wssPort: 443,
    wsPath: path ?? '',
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    channelAuthorization: {
      customHandler: channelAuthorizer(`${apiBaseUrl(config.apiBaseUrl)}/broadcasting/auth`),
    },
    ...testOverride,
  }
}
