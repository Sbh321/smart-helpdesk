import { copy } from '@/copy/en'
import { isApiError } from './errors'

/** True when the failure is an `ApiError` carrying this stable problem code (docs/07-api/errors.md). */
export function hasProblemCode(error: unknown, code: string): boolean {
  return isApiError(error) && error.code === code
}

/**
 * Our wording for the stable problem codes the SPA knows; `undefined` for every other failure, whose
 * caller falls back to the problem detail. `invalid_transition` is refined by `meta.reason`.
 */
export function problemMessage(error: unknown): string | undefined {
  if (!isApiError(error)) {
    return undefined
  }
  return problemCodeMessage(error.code, error.meta)
}

/** The wording of `problemMessage` for a bare code and its `meta` (a bulk row carries no `ApiError`). */
export function problemCodeMessage(
  code: string | null | undefined,
  meta: Record<string, unknown> = {},
): string | undefined {
  const text = copy.problems
  switch (code) {
    case 'resolution_comment_required':
      return text.resolutionCommentRequired
    case 'invalid_transition': {
      const reason = meta.reason
      if (reason === 'closed_as_duplicate') return text.closedAsDuplicate
      if (reason === 'reopen_window_expired') return text.reopenWindowExpired
      // Any other refusal is explained by the problem detail (it names the two statuses).
      return undefined
    }
    case 'already_decided':
      return text.alreadyDecided
    case 'already_assigned':
      return text.alreadyAssigned
    case 'no_eligible_agent':
      return text.noEligibleAgent
    case 'last_owner':
      return text.lastOwner
    case 'conflict':
      // `self`: disabling one's own account; other reasons are explained by the problem detail.
      return meta.reason === 'self' ? text.cannotDisableSelf : undefined
    default:
      return undefined
  }
}

/**
 * One failed row of a bulk answer (`{ok: false, code, detail, details}`): our wording for the code, then
 * the API's detail, then a generic line. `details` plays the part of a problem's `meta`.
 */
export function bulkRowMessage(row: {
  code: string | null
  detail: string | null
  details?: Record<string, unknown>
}): string {
  const known = problemCodeMessage(row.code, row.details ?? {})
  if (known) return known
  const text = copy.problems.bulk
  // A bulk row is one ticket among many: the generic codes get wording that names the ticket's case.
  if (row.code === 'forbidden') return text.forbidden
  if (row.code === 'not_found') return text.notFound
  if (row.detail) return row.detail
  if (row.code === 'invalid_transition') return text.invalidTransition
  return text.failed
}

export type AgentExclusion = { agent_id: string; reason: string; missing_skills?: string[] }

/** `meta.exclusions` of a 422 `no_eligible_agent`; malformed rows are dropped, never rendered raw. */
export function exclusionsOf(error: unknown): AgentExclusion[] {
  if (!hasProblemCode(error, 'no_eligible_agent') || !isApiError(error)) {
    return []
  }
  const rows = error.meta?.exclusions
  if (!Array.isArray(rows)) {
    return []
  }
  const exclusions: AgentExclusion[] = []
  for (const row of rows) {
    if (row === null || typeof row !== 'object') continue
    const { agent_id, reason, missing_skills } = row as Record<string, unknown>
    if (typeof agent_id !== 'string' || typeof reason !== 'string') continue
    const skills = Array.isArray(missing_skills)
      ? missing_skills.filter((skill): skill is string => typeof skill === 'string')
      : []
    exclusions.push({ agent_id, reason, ...(skills.length > 0 ? { missing_skills: skills } : {}) })
  }
  return exclusions
}
