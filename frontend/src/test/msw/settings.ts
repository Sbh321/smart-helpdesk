import { HttpResponse, http } from 'msw'
import { db, SETTINGS_DEFAULTS, type SettingsSectionResource } from './data'
import { apiUrl, problem } from './handlers'
import { validationFailed } from './list'

type Values = Record<string, unknown>

function isRecord(value: unknown): value is Values {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/** The API's merge: submitted keys replace leaves, objects merge key by key. */
function deepMerge(base: Values, patch: Values): Values {
  const merged: Values = { ...base }
  for (const [key, value] of Object.entries(patch)) {
    const current = merged[key]
    merged[key] = isRecord(value) && isRecord(current) ? deepMerge(current, value) : value
  }
  return merged
}

function resource(section: string): SettingsSectionResource {
  return {
    section,
    version: db.settingsVersion,
    values: db.settings[section] ?? {},
    defaults: SETTINGS_DEFAULTS[section] ?? {},
  }
}

/** 422 `validation_failed`: a malformed value. */
function malformed(section: string, values: Values): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if (section === 'general') {
    const name = typeof values.name === 'string' ? values.name.trim() : ''
    if (name.length < 2) errors.name = ['The name field must be at least 2 characters.']
  }
  if (section === 'branding') {
    const { primary } = values
    if (primary !== null && !(typeof primary === 'string' && /^#[0-9a-fA-F]{6}$/.test(primary)))
      errors.primary = ['The primary field format is invalid.']
  }
  return errors
}

/** 422 `settings_invalid`: values that do not fit together. */
function inconsistent(section: string, values: Values): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if (section === 'automation.priority' && isRecord(values.baseline)) {
    const { weights, thresholds } = values.baseline
    if (isRecord(weights)) {
      const sum = Object.values(weights).reduce<number>((total, weight) => total + Number(weight), 0)
      if (Math.abs(sum - 1) > 0.001) errors['baseline.weights'] = ['The four weights must add up to 1.']
    }
    if (isRecord(thresholds)) {
      const { P1, P2, P3 } = thresholds
      if (!(Number(P1) > Number(P2) && Number(P2) > Number(P3)))
        errors['baseline.thresholds'] = ['Thresholds must decrease from P1 to P3.']
    }
  }
  return errors
}

/** `GET/PATCH /v1/settings…`: in-memory sections that merge and validate like the API (M2-01). */
export const settingsHandlers = [
  http.get(apiUrl('/settings'), () =>
    HttpResponse.json({ data: Object.keys(db.settings).map((section) => resource(section)) }),
  ),
  http.get(apiUrl('/settings/{section}'), ({ params }) => {
    const section = String(params.section)
    if (!(section in db.settings)) return problem(404, 'not_found', { title: 'Not found' })
    return HttpResponse.json({ data: resource(section) })
  }),
  http.patch(apiUrl('/settings/{section}'), async ({ params, request }) => {
    const section = String(params.section)
    const current = db.settings[section]
    if (!current) return problem(404, 'not_found', { title: 'Not found' })
    const body = await request.json()
    const merged = deepMerge(current, isRecord(body) ? body : {})
    const invalid = malformed(section, merged)
    if (Object.keys(invalid).length > 0) return validationFailed(invalid)
    const errors = inconsistent(section, merged)
    if (Object.keys(errors).length > 0)
      return problem(422, 'settings_invalid', { title: 'These settings do not fit together.', errors })
    db.settings[section] = merged
    db.settingsVersion += 1
    return HttpResponse.json({ data: resource(section) })
  }),
]
