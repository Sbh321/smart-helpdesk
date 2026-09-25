/**
 * Browser test files run in parallel tabs on one origin and share `localStorage`. A file that writes a
 * key another file reads (the recent workspaces of M5-03) calls this at module scope to get storage of
 * its own for the whole file, so the files cannot see each other's entries.
 */
class MemoryStorage implements Storage {
  private readonly values = new Map<string, string>()
  get length(): number {
    return this.values.size
  }
  clear(): void {
    this.values.clear()
  }
  getItem(key: string): string | null {
    return this.values.get(key) ?? null
  }
  key(index: number): string | null {
    return [...this.values.keys()][index] ?? null
  }
  removeItem(key: string): void {
    this.values.delete(key)
  }
  setItem(key: string, value: string): void {
    this.values.set(key, String(value))
  }
}

export function isolateLocalStorage(): void {
  Object.defineProperty(window, 'localStorage', { value: new MemoryStorage(), configurable: true })
}
