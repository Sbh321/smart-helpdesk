import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/lib/api/errors'
import { type BulkRow, chunk, runInChunks } from './bulk-actions'

const ok = (id: string): BulkRow => ({ ticket_id: id, ok: true, code: null, detail: null, details: {} })
const ids = (count: number) => Array.from({ length: count }, (_, index) => `t${index}`)

describe('chunk', () => {
  it('splits into slices of at most 100', () => {
    expect(chunk(ids(250)).map((part) => part.length)).toEqual([100, 100, 50])
    expect(chunk(ids(100))).toHaveLength(1)
    expect(chunk([])).toEqual([])
  })
})

describe('runInChunks', () => {
  it('sends one request per 100 distinct ids and adds up the rows', async () => {
    const send = vi.fn(async (ticketIds: string[]) => ({
      data: ticketIds.map((id, index) =>
        index === 0 ? { ...ok(id), ok: false, code: 'invalid_transition' } : ok(id),
      ),
    }))
    const progress = vi.fn()
    const result = await runInChunks([...ids(205), 't0'], send, progress)

    expect(send).toHaveBeenCalledTimes(3)
    expect(send.mock.calls.map(([part]) => part.length)).toEqual([100, 100, 5])
    expect(result).toMatchObject({ total: 205, succeeded: 202, failed: 3 })
    expect(progress).toHaveBeenLastCalledWith(205, 205)
  })

  it('rethrows a failure of the first request, and turns a later one into failed rows', async () => {
    const forbidden = new ApiError({ status: 403, code: 'forbidden', title: 'Forbidden', detail: 'No.' })
    await expect(runInChunks(ids(3), () => Promise.reject(forbidden))).rejects.toBe(forbidden)

    let call = 0
    const result = await runInChunks(ids(150), async (ticketIds) => {
      call += 1
      if (call === 2) throw forbidden
      return { data: ticketIds.map(ok) }
    })
    expect(result).toMatchObject({ total: 150, succeeded: 100, failed: 50 })
    expect(result.rows.at(-1)).toMatchObject({ ok: false, code: 'forbidden', detail: 'No.' })
  })
})
