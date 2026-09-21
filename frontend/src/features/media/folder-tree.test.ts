import { describe, expect, it } from 'vitest'
import type { MediaFolder } from './api/media-queries'
import { ancestorIds, visibleFolders } from './folder-tree'

function folder(id: string, parent_id: string | null, name = id): MediaFolder {
  return { id, parent_id, name, system_key: null, created_at: '', updated_at: '' }
}

const folders = [
  folder('brand', null, 'Brand'),
  folder('logos', 'brand', 'Logos'),
  folder('old', 'logos', 'Old'),
  folder('tickets', null, 'Tickets'),
]

describe('visibleFolders', () => {
  it('hides children of collapsed folders and numbers levels and positions', () => {
    expect(
      visibleFolders(folders, new Set()).map((node) => [node.folder.id, node.level, node.hasChildren]),
    ).toEqual([
      ['brand', 1, true],
      ['tickets', 1, false],
    ])
    const open = visibleFolders(folders, new Set(['brand', 'logos']))
    expect(open.map((node) => `${node.folder.id}:${node.level}:${node.position}/${node.siblings}`)).toEqual([
      'brand:1:1/2',
      'logos:2:1/1',
      'old:3:1/1',
      'tickets:1:2/2',
    ])
  })

  it('keeps an orphan as a root and survives a parent cycle', () => {
    expect(visibleFolders([folder('lost', 'gone')], new Set()).map((node) => node.folder.id)).toEqual([
      'lost',
    ])
    const cycle = [folder('a', 'b'), folder('b', 'a')]
    expect(() => visibleFolders(cycle, new Set(['a', 'b']))).not.toThrow()
  })
})

describe('ancestorIds', () => {
  it('lists the parents of a folder, nearest first', () => {
    expect(ancestorIds(folders, 'old')).toEqual(['logos', 'brand'])
    expect(ancestorIds(folders, 'brand')).toEqual([])
    expect(ancestorIds(folders, null)).toEqual([])
  })
})
