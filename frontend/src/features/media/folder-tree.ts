import type { MediaFolder } from './api/media-queries'

export interface FolderNode {
  folder: MediaFolder
  /** 1 for a root folder, as `aria-level` counts. */
  level: number
  hasChildren: boolean
  /** Position among its siblings, 1-based, and how many siblings there are (`aria-posinset/setsize`). */
  position: number
  siblings: number
}

/**
 * The rows a tree shows: depth-first, children only under expanded parents. A folder whose parent is
 * missing from the list is treated as a root rather than lost.
 */
export function visibleFolders(folders: readonly MediaFolder[], expanded: ReadonlySet<string>): FolderNode[] {
  const ids = new Set(folders.map((folder) => folder.id))
  const childrenOf = (parentId: string | null) =>
    folders
      .filter((folder) =>
        parentId === null
          ? folder.parent_id === null || !ids.has(folder.parent_id)
          : folder.parent_id === parentId,
      )
      .sort((a, b) => a.name.localeCompare(b.name))

  const walk = (parentId: string | null, level: number, seen: ReadonlySet<string>): FolderNode[] => {
    const siblings = childrenOf(parentId)
    return siblings.flatMap((folder, index) => {
      if (seen.has(folder.id)) return []
      const hasChildren = folders.some((other) => other.parent_id === folder.id)
      const node = { folder, level, hasChildren, position: index + 1, siblings: siblings.length }
      return expanded.has(folder.id) && hasChildren
        ? [node, ...walk(folder.id, level + 1, new Set([...seen, folder.id]))]
        : [node]
    })
  }
  return walk(null, 1, new Set())
}

/** The ids above a folder, so a folder selected from the URL can be revealed. */
export function ancestorIds(folders: readonly MediaFolder[], id: string | null): string[] {
  const found: string[] = []
  let current = folders.find((folder) => folder.id === id)
  while (current?.parent_id && !found.includes(current.parent_id)) {
    found.push(current.parent_id)
    const parentId = current.parent_id
    current = folders.find((folder) => folder.id === parentId)
  }
  return found
}
