import { describe, expect, it } from 'vitest'
import {
  forgetWorkspace,
  RECENT_WORKSPACES_KEY,
  readRecentWorkspaces,
  rememberedWorkspaceName,
  rememberWorkspace,
} from './recent-workspaces'

function memoryStorage(initial?: string) {
  const values = new Map<string, string>(initial === undefined ? [] : [[RECENT_WORKSPACES_KEY, initial]])
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => void values.set(key, value),
  }
}

describe('recent workspaces', () => {
  it('keeps the newest first, without duplicates, and at most five', () => {
    const storage = memoryStorage()
    for (const [index, slug] of ['a', 'b', 'c', 'd', 'e', 'f'].entries()) {
      rememberWorkspace(
        { slug, name: slug.toUpperCase() },
        new Date(Date.UTC(2026, 8, 25, 10, index)),
        storage,
      )
    }
    rememberWorkspace({ slug: 'd', name: 'Delta' }, new Date(Date.UTC(2026, 8, 25, 11)), storage)

    expect(readRecentWorkspaces(storage).map((item) => item.slug)).toEqual(['d', 'f', 'e', 'c', 'b'])
    expect(rememberedWorkspaceName('d', storage)).toBe('Delta')
  })

  it('forgets one workspace', () => {
    const storage = memoryStorage()
    rememberWorkspace({ slug: 'acme', name: 'Acme' }, new Date(), storage)
    rememberWorkspace({ slug: 'globex', name: 'Globex' }, new Date(), storage)

    expect(forgetWorkspace('acme', storage).map((item) => item.slug)).toEqual(['globex'])
  })

  it('ignores stored values it cannot trust', () => {
    expect(readRecentWorkspaces(memoryStorage('not json'))).toEqual([])
    expect(
      readRecentWorkspaces(memoryStorage('[{"slug":"Bad Slug","name":"x","lastUsedAt":"now"}]')),
    ).toEqual([])
  })

  it('works without storage at all', () => {
    expect(readRecentWorkspaces(undefined)).toEqual([])
    expect(rememberWorkspace({ slug: 'acme', name: 'Acme' }, new Date(), undefined)).toHaveLength(1)
  })

  it('refuses a slug that is not one', () => {
    const storage = memoryStorage()
    rememberWorkspace({ slug: '../evil', name: 'Evil' }, new Date(), storage)
    expect(readRecentWorkspaces(storage)).toEqual([])
  })
})
