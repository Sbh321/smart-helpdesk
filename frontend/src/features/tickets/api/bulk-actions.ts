import { api, unwrapBody } from '@/lib/api/client'
import { isApiError } from '@/lib/api/errors'
import type { components } from '@/lib/api/schema'

export type BulkRow = components['schemas']['BulkRowResource']
export type BulkTransitionInput = components['schemas']['BulkTransitionRequest']
export type BulkAssignInput = components['schemas']['BulkAssignRequest']

/** One bulk answer (`{data, meta}`), for one request or accumulated over several. */
export interface BulkResult {
  rows: BulkRow[]
  total: number
  succeeded: number
  failed: number
}

/** The API takes at most 100 ticket ids per bulk request (docs/07-api/endpoints.md). */
export const BULK_LIMIT = 100

export function chunk<T>(items: readonly T[], size: number = BULK_LIMIT): T[][] {
  const chunks: T[][] = []
  for (let start = 0; start < items.length; start += size) {
    chunks.push(items.slice(start, start + size))
  }
  return chunks
}

/**
 * Sends a selection in requests of at most 100 ids, one after the other, and adds up the per-row answers.
 * A failure of the whole first request (403, 422) is rethrown: nothing was changed, so the dialog shows
 * it. A later request that fails as a whole turns its own ids into failed rows with that problem's code,
 * because the earlier requests already changed tickets and their results must still be shown.
 */
export async function runInChunks(
  ids: readonly string[],
  send: (ticketIds: string[]) => Promise<{ data: BulkRow[] }>,
  onProgress?: (done: number, total: number) => void,
): Promise<BulkResult> {
  const rows: BulkRow[] = []
  const unique = [...new Set(ids)]
  const chunks = chunk(unique)
  let done = 0
  onProgress?.(0, unique.length)
  for (const [index, ticketIds] of chunks.entries()) {
    try {
      const answer = await send(ticketIds)
      rows.push(...answer.data)
    } catch (error) {
      if (index === 0) throw error
      const code = isApiError(error) ? error.code : null
      const detail = isApiError(error) ? (error.detail ?? error.title) : null
      rows.push(
        ...ticketIds.map((ticketId) => ({ ticket_id: ticketId, ok: false, code, detail, details: {} })),
      )
    }
    done += ticketIds.length
    onProgress?.(done, unique.length)
  }
  const succeeded = rows.filter((row) => row.ok).length
  return { rows, total: rows.length, succeeded, failed: rows.length - succeeded }
}

export function bulkTransition(input: BulkTransitionInput) {
  return unwrapBody(api().POST('/tickets/bulk/transition', { body: input }))
}

export function bulkAssign(input: BulkAssignInput) {
  return unwrapBody(api().POST('/tickets/bulk/assign', { body: input }))
}
