import { describe, expect, it, vi } from 'vitest'
import type { RuntimeConfig } from '@/lib/config'
import { channelAuthorizer, realtimeEchoOptions } from './echo-options'

const config: RuntimeConfig = {
  apiBaseUrl: 'https://api.shp.test',
  appMode: 'tenant',
  platformDomain: 'shp.test',
  storagePublicEndpoint: 'https://files.shp.test',
  docsUrl: 'https://docs.shp.test',
  platformDocsUrl: 'https://platform-docs.shp.test',
  realtime: { enabled: true, key: 'public-key', host: 'api.shp.test', path: '' },
}

describe('realtimeEchoOptions', () => {
  it('points Echo at Reverb through the proxy on 443', () => {
    expect(realtimeEchoOptions(config)).toMatchObject({
      broadcaster: 'reverb',
      key: 'public-key',
      wsHost: 'api.shp.test',
      wssPort: 443,
      wsPath: '',
      forceTLS: true,
      enabledTransports: ['ws', 'wss'],
    })
  })

  it('keeps the /api prefix of the single-host layout', () => {
    expect(realtimeEchoOptions({ ...config, realtime: { ...config.realtime, path: '/api' } })?.wsPath).toBe(
      '/api',
    )
  })

  it('is null when the deployment has no Reverb or no key', () => {
    expect(realtimeEchoOptions({ ...config, realtime: { enabled: false } })).toBeNull()
    expect(realtimeEchoOptions({ ...config, realtime: { enabled: true, host: 'api.shp.test' } })).toBeNull()
  })
})

describe('channelAuthorizer', () => {
  it('posts socket and channel with the session cookie and the XSRF header', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => Response.json({ auth: 'public-key:signature' }))
    const callback = vi.fn()

    channelAuthorizer(
      'https://api.shp.test/v1/broadcasting/auth',
      fetcher,
      () => 'XSRF-TOKEN=abc%3D',
    )({ socketId: '1.2', channelName: 'private-tenants.t.tickets' }, callback)

    await vi.waitFor(() => expect(callback).toHaveBeenCalled())
    expect(callback).toHaveBeenCalledWith(null, { auth: 'public-key:signature' })
    const [url, init = {}] = fetcher.mock.calls[0] ?? []
    expect(url).toBe('https://api.shp.test/v1/broadcasting/auth')
    expect(init).toMatchObject({ method: 'POST', credentials: 'include' })
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=')
    expect(JSON.parse(String(init.body))).toEqual({
      socket_id: '1.2',
      channel_name: 'private-tenants.t.tickets',
    })
  })

  it('reports a refused channel as an error', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => new Response(null, { status: 403 }))
    const callback = vi.fn()

    channelAuthorizer(
      'https://api.shp.test/v1/broadcasting/auth',
      fetcher,
      () => '',
    )({ socketId: '1.2', channelName: 'private-tenants.other.tickets' }, callback)

    await vi.waitFor(() => expect(callback).toHaveBeenCalled())
    expect(callback.mock.calls[0]?.[0]).toBeInstanceOf(Error)
    expect(callback.mock.calls[0]?.[1]).toBeNull()
  })
})
