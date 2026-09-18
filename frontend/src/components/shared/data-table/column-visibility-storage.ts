/**
 * Column visibility is a per-browser preference, so it lives in localStorage (one key per table id), not
 * in the URL. Storage can be missing or throw (private windows, blocked site data), and a stored value
 * can be anything a previous version wrote; every failure reads as "no preference".
 */
export type ColumnVisibility = Record<string, boolean>

export function columnVisibilityKey(tableId: string): string {
  return `sh.table.${tableId}.columns`
}

export function readColumnVisibility(tableId: string): ColumnVisibility {
  try {
    const raw = globalThis.localStorage?.getItem(columnVisibilityKey(tableId))
    if (!raw) {
      return {}
    }
    const parsed: unknown = JSON.parse(raw)
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return {}
    }
    return Object.fromEntries(
      Object.entries(parsed).filter((entry): entry is [string, boolean] => typeof entry[1] === 'boolean'),
    )
  } catch {
    return {}
  }
}

export function writeColumnVisibility(tableId: string, visibility: ColumnVisibility): void {
  try {
    globalThis.localStorage?.setItem(columnVisibilityKey(tableId), JSON.stringify(visibility))
  } catch {
    // Quota or privacy mode: the preference simply is not remembered.
  }
}
