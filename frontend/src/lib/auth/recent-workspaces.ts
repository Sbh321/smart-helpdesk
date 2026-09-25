import { isWorkspaceSlug } from './guards'

/**
 * Workspaces signed in to on this device (M5-03), offered first on the workspace entry page. A
 * per-viewer convenience only: stored in the browser, never sent anywhere, and the page works the same
 * when storage is refused (private windows), full or cleared.
 */
export interface RecentWorkspace {
  slug: string
  /** The workspace's display name as the session reported it at sign-in. */
  name: string
  /** ISO instant of the last sign-in from this device. */
  lastUsedAt: string
}

export const RECENT_WORKSPACES_KEY = 'sh.recent-workspaces'
const LIMIT = 5

function isRecentWorkspace(value: unknown): value is RecentWorkspace {
  if (typeof value !== 'object' || value === null) return false
  const entry = value as Record<string, unknown>
  return (
    typeof entry.slug === 'string' &&
    isWorkspaceSlug(entry.slug) &&
    typeof entry.name === 'string' &&
    typeof entry.lastUsedAt === 'string'
  )
}

/** Newest first; anything unreadable is ignored rather than trusted. */
export function readRecentWorkspaces(
  storage: Pick<Storage, 'getItem'> | undefined = safeStorage(),
): RecentWorkspace[] {
  try {
    const parsed: unknown = JSON.parse(storage?.getItem(RECENT_WORKSPACES_KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter(isRecentWorkspace).slice(0, LIMIT) : []
  } catch {
    return []
  }
}

function write(entries: RecentWorkspace[], storage: Pick<Storage, 'setItem'> | undefined): void {
  try {
    storage?.setItem(RECENT_WORKSPACES_KEY, JSON.stringify(entries.slice(0, LIMIT)))
  } catch {
    // Storage refused or full: the list is a convenience, so losing it is fine.
  }
}

/** Moves the workspace to the top of the list, keeping at most five. */
export function rememberWorkspace(
  workspace: { slug: string; name: string },
  now: Date = new Date(),
  storage: Pick<Storage, 'getItem' | 'setItem'> | undefined = safeStorage(),
): RecentWorkspace[] {
  if (!isWorkspaceSlug(workspace.slug)) return readRecentWorkspaces(storage)
  const entry: RecentWorkspace = {
    slug: workspace.slug,
    name: workspace.name || workspace.slug,
    lastUsedAt: now.toISOString(),
  }
  const entries = [entry, ...readRecentWorkspaces(storage).filter((item) => item.slug !== workspace.slug)]
  write(entries, storage)
  return entries.slice(0, LIMIT)
}

/** Removes one workspace from this device's list. */
export function forgetWorkspace(
  slug: string,
  storage: Pick<Storage, 'getItem' | 'setItem'> | undefined = safeStorage(),
): RecentWorkspace[] {
  const entries = readRecentWorkspaces(storage).filter((item) => item.slug !== slug)
  write(entries, storage)
  return entries
}

/** The display name remembered for a workspace, if this device has signed in to it. */
export function rememberedWorkspaceName(
  slug: string,
  storage: Pick<Storage, 'getItem'> | undefined = safeStorage(),
): string | undefined {
  return readRecentWorkspaces(storage).find((item) => item.slug === slug)?.name
}

function safeStorage(): Storage | undefined {
  try {
    return typeof window === 'undefined' ? undefined : window.localStorage
  } catch {
    return undefined
  }
}
