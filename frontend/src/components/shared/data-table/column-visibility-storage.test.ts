import { afterEach, describe, expect, it, vi } from 'vitest'
import { columnVisibilityKey, readColumnVisibility, writeColumnVisibility } from './column-visibility-storage'

function memoryStorage(initial: Record<string, string> = {}): Storage {
  const values = new Map(Object.entries(initial))
  return {
    get length() {
      return values.size
    },
    clear: () => values.clear(),
    getItem: (key) => values.get(key) ?? null,
    key: (index) => [...values.keys()][index] ?? null,
    removeItem: (key) => void values.delete(key),
    setItem: (key, value) => void values.set(key, value),
  }
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('column visibility storage', () => {
  it('uses one key per table id', () => {
    expect(columnVisibilityKey('contacts')).toBe('sh.table.contacts.columns')
  })

  it('round-trips a visibility map', () => {
    vi.stubGlobal('localStorage', memoryStorage())
    writeColumnVisibility('contacts', { phone: false, email: true })
    expect(readColumnVisibility('contacts')).toEqual({ phone: false, email: true })
    expect(readColumnVisibility('tickets')).toEqual({})
  })

  it('ignores malformed or foreign values', () => {
    vi.stubGlobal(
      'localStorage',
      memoryStorage({
        'sh.table.a.columns': 'not json',
        'sh.table.b.columns': '[false]',
        'sh.table.c.columns': '{"phone":"no","email":false}',
        'sh.table.d.columns': 'null',
      }),
    )
    expect(readColumnVisibility('a')).toEqual({})
    expect(readColumnVisibility('b')).toEqual({})
    expect(readColumnVisibility('c')).toEqual({ email: false })
    expect(readColumnVisibility('d')).toEqual({})
  })

  it('treats storage that throws as no preference', () => {
    const throwing = memoryStorage()
    throwing.getItem = () => {
      throw new DOMException('denied', 'SecurityError')
    }
    throwing.setItem = () => {
      throw new DOMException('full', 'QuotaExceededError')
    }
    vi.stubGlobal('localStorage', throwing)
    expect(readColumnVisibility('contacts')).toEqual({})
    expect(() => writeColumnVisibility('contacts', { phone: false })).not.toThrow()
  })

  it('works when there is no storage at all', () => {
    vi.stubGlobal('localStorage', undefined)
    expect(readColumnVisibility('contacts')).toEqual({})
    expect(() => writeColumnVisibility('contacts', {})).not.toThrow()
  })
})
