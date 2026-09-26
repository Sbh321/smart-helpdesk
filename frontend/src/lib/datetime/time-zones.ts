import { TIME_ZONE_IDS } from './time-zone-ids'

/** True for an IANA zone name this browser knows (`Asia/Kathmandu`, `UTC`). */
export function isTimeZone(value: string): boolean {
  try {
    new Intl.DateTimeFormat('en', { timeZone: value })
    return true
  } catch {
    return false
  }
}

/** Every zone the API accepts, `UTC` first; the options of `TimeZoneField`. */
export function timeZoneNames(): readonly string[] {
  return ZONES
}

const ZONES: readonly string[] = ['UTC', ...TIME_ZONE_IDS.filter((zone) => zone !== 'UTC')]
const KNOWN = new Set(ZONES)

/**
 * Old names that Chromium browsers still report (their ICU data keeps them as the canonical ids) for
 * zones the time zone database has renamed; the API only accepts the current names.
 */
const RENAMED: Readonly<Record<string, string>> = {
  'Africa/Asmera': 'Africa/Asmara',
  'America/Buenos_Aires': 'America/Argentina/Buenos_Aires',
  'America/Catamarca': 'America/Argentina/Catamarca',
  'America/Coral_Harbour': 'America/Atikokan',
  'America/Cordoba': 'America/Argentina/Cordoba',
  'America/Godthab': 'America/Nuuk',
  'America/Indianapolis': 'America/Indiana/Indianapolis',
  'America/Jujuy': 'America/Argentina/Jujuy',
  'America/Louisville': 'America/Kentucky/Louisville',
  'America/Mendoza': 'America/Argentina/Mendoza',
  'Asia/Calcutta': 'Asia/Kolkata',
  'Asia/Katmandu': 'Asia/Kathmandu',
  'Asia/Rangoon': 'Asia/Yangon',
  'Asia/Saigon': 'Asia/Ho_Chi_Minh',
  'Atlantic/Faeroe': 'Atlantic/Faroe',
  'Europe/Kiev': 'Europe/Kyiv',
  'Pacific/Enderbury': 'Pacific/Kanton',
  'Pacific/Ponape': 'Pacific/Pohnpei',
  'Pacific/Truk': 'Pacific/Chuuk',
  'Etc/UTC': 'UTC',
  'Etc/GMT': 'UTC',
}

/** The zone name the API accepts for `zone` (an old name mapped to its current one), or null. */
export function canonicalTimeZone(zone: string): string | null {
  const name = RENAMED[zone] ?? zone
  return KNOWN.has(name) ? name : null
}

/** The browser's own zone as the API names it, or `UTC` when it cannot be told. */
export function browserTimeZone(): string {
  try {
    return canonicalTimeZone(Intl.DateTimeFormat().resolvedOptions().timeZone) ?? 'UTC'
  } catch {
    return 'UTC'
  }
}

/** The zone's current offset, for example `UTC+05:45`, or an empty string if the browser lacks it. */
export function timeZoneOffset(zone: string, at: Date = new Date()): string {
  try {
    const part = new Intl.DateTimeFormat('en', { timeZone: zone, timeZoneName: 'longOffset' })
      .formatToParts(at)
      .find((p) => p.type === 'timeZoneName')?.value
    if (!part) return ''
    return part === 'GMT' ? 'UTC+00:00' : part.replace('GMT', 'UTC')
  } catch {
    return ''
  }
}
