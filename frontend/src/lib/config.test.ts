import { describe, expect, it } from 'vitest'
import { loadRuntimeConfig } from './config'

const validConfig = {
  apiBaseUrl: 'https://api.shp.localhost',
  appMode: 'tenant',
  platformDomain: 'shp.localhost',
  storagePublicEndpoint: 'https://files.shp.localhost',
  realtime: { enabled: false },
}

const fakeFetch = (body: unknown, status = 200) =>
  (async () => new Response(JSON.stringify(body), { status })) as unknown as typeof fetch

describe('loadRuntimeConfig', () => {
  it('parses a valid configuration', async () => {
    await expect(loadRuntimeConfig(fakeFetch(validConfig))).resolves.toMatchObject({ appMode: 'tenant' })
  })

  it('rejects an invalid configuration', async () => {
    await expect(loadRuntimeConfig(fakeFetch({ ...validConfig, appMode: 'other' }))).rejects.toThrow()
  })

  it('fails clearly when the file is missing', async () => {
    await expect(loadRuntimeConfig(fakeFetch({}, 404))).rejects.toThrow('Could not load /config.json (404)')
  })
})
