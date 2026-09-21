/** True for an IANA zone name this browser knows (`Asia/Kathmandu`, `UTC`). */
export function isTimeZone(value: string): boolean {
  try {
    new Intl.DateTimeFormat('en', { timeZone: value })
    return true
  } catch {
    return false
  }
}

let cached: readonly string[] | undefined

/** Every IANA zone the browser offers, with `UTC` first; the suggestions of `TimeZoneField`. */
export function timeZoneNames(): readonly string[] {
  if (!cached) {
    const supported = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []
    cached = ['UTC', ...supported.filter((zone) => zone !== 'UTC')]
  }
  return cached
}
