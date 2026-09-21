import { ChevronDownIcon, ChevronRightIcon, FolderIcon, FolderOpenIcon, LibraryIcon } from 'lucide-react'
import { type KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'
import type { MediaFolder } from '../api/media-queries'
import { ancestorIds, visibleFolders } from '../folder-tree'

/** The tree's first row: every folder. It has no id in the API, so the tree uses this key for it. */
const ALL = '__all__'

/**
 * Folders as a WAI-ARIA tree (APG "Tree View"): one tab stop with a roving `tabindex`, Up/Down move
 * between visible rows, Right opens a folder or enters it, Left closes it or goes to its parent,
 * Home/End jump, Enter or Space selects. Selection follows the URL filter, not the focus.
 */
export function MediaFolderTree({
  folders,
  selectedId,
  onSelect,
}: {
  folders: readonly MediaFolder[]
  selectedId: string | null
  onSelect: (id: string | null) => void
}) {
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set())
  const [focused, setFocused] = useState(selectedId ?? ALL)
  const items = useRef(new Map<string, HTMLDivElement>())
  const nodes = useMemo(() => visibleFolders(folders, expanded), [folders, expanded])
  const keys = [ALL, ...nodes.map((node) => node.folder.id)]
  // A focused folder can disappear (deleted, or its parent collapsed): the tab stop falls back to the top.
  const tabStop = keys.includes(focused) ? focused : ALL

  // Reveal the folder the URL selects, for example after a reload.
  useEffect(() => {
    const parents = ancestorIds(folders, selectedId)
    if (parents.length > 0) setExpanded((current) => new Set([...current, ...parents]))
  }, [folders, selectedId])

  function focus(key: string | undefined) {
    if (key === undefined) return
    setFocused(key)
    items.current.get(key)?.focus()
  }

  function toggle(id: string, open: boolean) {
    setExpanded((current) => {
      const next = new Set(current)
      if (open) next.add(id)
      else next.delete(id)
      return next
    })
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>, key: string) {
    if (event.target !== event.currentTarget) return
    const index = keys.indexOf(key)
    const node = nodes.find((row) => row.folder.id === key)
    const handled = () => {
      event.preventDefault()
      event.stopPropagation()
    }
    switch (event.key) {
      case 'ArrowDown':
        handled()
        return focus(keys[index + 1])
      case 'ArrowUp':
        handled()
        return focus(keys[index - 1])
      case 'Home':
        handled()
        return focus(keys[0])
      case 'End':
        handled()
        return focus(keys.at(-1))
      case 'ArrowRight':
        handled()
        if (!node?.hasChildren) return
        if (expanded.has(key)) return focus(keys[index + 1])
        return toggle(key, true)
      case 'ArrowLeft':
        handled()
        if (node?.hasChildren && expanded.has(key)) return toggle(key, false)
        return focus(node?.folder.parent_id ?? undefined)
      case 'Enter':
      case ' ':
        handled()
        return onSelect(key === ALL ? null : key)
      default:
    }
  }

  const rowClass = (selected: boolean) =>
    cn(
      'flex min-h-8 cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-sm outline-none',
      'hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring',
      selected && 'bg-accent font-medium text-accent-foreground',
    )

  return (
    <div role="tree" aria-label={copy.media.folders} className="space-y-0.5">
      <div
        role="treeitem"
        aria-level={1}
        aria-selected={selectedId === null}
        tabIndex={tabStop === ALL ? 0 : -1}
        ref={(element) => {
          if (element) items.current.set(ALL, element)
          else items.current.delete(ALL)
        }}
        className={rowClass(selectedId === null)}
        onClick={() => {
          setFocused(ALL)
          onSelect(null)
        }}
        onKeyDown={(event) => onKeyDown(event, ALL)}
      >
        <LibraryIcon aria-hidden="true" className="size-4 shrink-0" />
        {copy.media.allFolders}
      </div>
      {nodes.map(({ folder, level, hasChildren, position, siblings }) => {
        const open = expanded.has(folder.id)
        const selected = selectedId === folder.id
        const Chevron = open ? ChevronDownIcon : ChevronRightIcon
        const Icon = open ? FolderOpenIcon : FolderIcon
        return (
          <div
            key={folder.id}
            role="treeitem"
            aria-level={level}
            aria-posinset={position}
            aria-setsize={siblings}
            aria-selected={selected}
            {...(hasChildren ? { 'aria-expanded': open } : {})}
            tabIndex={tabStop === folder.id ? 0 : -1}
            ref={(element) => {
              if (element) items.current.set(folder.id, element)
              else items.current.delete(folder.id)
            }}
            className={rowClass(selected)}
            style={{ paddingInlineStart: `${0.5 + (level - 1) * 0.875}rem` }}
            onClick={() => {
              setFocused(folder.id)
              onSelect(folder.id)
            }}
            onKeyDown={(event) => onKeyDown(event, folder.id)}
          >
            {hasChildren ? (
              <Chevron
                aria-hidden="true"
                className="size-4 shrink-0 text-muted-foreground"
                onClick={(event) => {
                  event.stopPropagation()
                  toggle(folder.id, !open)
                }}
              />
            ) : (
              <span aria-hidden="true" className="size-4 shrink-0" />
            )}
            <Icon aria-hidden="true" className="size-4 shrink-0" />
            <span className="min-w-0 truncate">{folder.name}</span>
          </div>
        )
      })}
    </div>
  )
}
