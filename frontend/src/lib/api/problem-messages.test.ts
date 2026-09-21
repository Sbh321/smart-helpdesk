import { describe, expect, it } from 'vitest'
import { copy } from '@/copy/en'
import { ApiError } from './errors'
import { bulkRowMessage, exclusionsOf, hasProblemCode, problemMessage } from './problem-messages'

function apiError(status: number, code: string, meta?: Record<string, unknown>): ApiError {
  return new ApiError({ status, code, title: code, detail: 'Server detail.', meta })
}

describe('problemMessage', () => {
  it('uses our wording for the stable codes', () => {
    expect(problemMessage(apiError(422, 'resolution_comment_required', { field: 'comment' }))).toBe(
      copy.problems.resolutionCommentRequired,
    )
    expect(problemMessage(apiError(409, 'already_decided'))).toBe(copy.problems.alreadyDecided)
    expect(problemMessage(apiError(409, 'already_assigned'))).toBe(copy.problems.alreadyAssigned)
    expect(problemMessage(apiError(422, 'no_eligible_agent'))).toBe(copy.problems.noEligibleAgent)
  })

  it('words last_owner and the self conflict of the user endpoints', () => {
    expect(problemMessage(apiError(422, 'last_owner'))).toBe(copy.problems.lastOwner)
    expect(problemMessage(apiError(409, 'conflict', { reason: 'self' }))).toBe(
      copy.problems.cannotDisableSelf,
    )
    expect(problemMessage(apiError(409, 'conflict', { reason: 'already_accepted' }))).toBeUndefined()
  })

  it('refines invalid_transition by meta.reason', () => {
    expect(problemMessage(apiError(422, 'invalid_transition', { reason: 'closed_as_duplicate' }))).toBe(
      copy.problems.closedAsDuplicate,
    )
    expect(problemMessage(apiError(422, 'invalid_transition', { reason: 'reopen_window_expired' }))).toBe(
      copy.problems.reopenWindowExpired,
    )
    expect(problemMessage(apiError(422, 'invalid_transition', { reason: 'something_new' }))).toBeUndefined()
    expect(problemMessage(apiError(422, 'invalid_transition'))).toBeUndefined()
  })

  it('leaves unknown codes and foreign errors to the caller', () => {
    expect(problemMessage(apiError(500, 'server_error'))).toBeUndefined()
    expect(problemMessage(new Error('boom'))).toBeUndefined()
    expect(hasProblemCode(new Error('boom'), 'already_decided')).toBe(false)
    expect(hasProblemCode(apiError(409, 'already_decided'), 'already_decided')).toBe(true)
  })
})

describe('exclusionsOf', () => {
  it('reads meta.exclusions and drops malformed rows', () => {
    const error = apiError(422, 'no_eligible_agent', {
      exclusions: [
        { agent_id: 'a1', reason: 'at_capacity' },
        { agent_id: 'a2', reason: 'missing_skill', missing_skills: ['billing', 7] },
        { agent_id: 3, reason: 'inactive' },
        null,
        'nonsense',
      ],
    })
    expect(exclusionsOf(error)).toEqual([
      { agent_id: 'a1', reason: 'at_capacity' },
      { agent_id: 'a2', reason: 'missing_skill', missing_skills: ['billing'] },
    ])
  })

  it('is empty for other codes and for a missing list', () => {
    expect(exclusionsOf(apiError(422, 'no_eligible_agent'))).toEqual([])
    expect(
      exclusionsOf(apiError(409, 'already_assigned', { exclusions: [{ agent_id: 'a', reason: 'x' }] })),
    ).toEqual([])
    expect(exclusionsOf(undefined)).toEqual([])
  })
})

describe('bulkRowMessage', () => {
  it('words a failed bulk row: known code, then the bulk wording, then the detail, then a generic line', () => {
    expect(bulkRowMessage({ code: 'resolution_comment_required', detail: 'x' })).toBe(
      copy.problems.resolutionCommentRequired,
    )
    expect(
      bulkRowMessage({
        code: 'invalid_transition',
        detail: null,
        details: { reason: 'reopen_window_expired' },
      }),
    ).toBe(copy.problems.reopenWindowExpired)
    expect(bulkRowMessage({ code: 'forbidden', detail: 'This action is unauthorized.' })).toBe(
      copy.problems.bulk.forbidden,
    )
    expect(bulkRowMessage({ code: 'not_found', detail: null })).toBe(copy.problems.bulk.notFound)
    expect(bulkRowMessage({ code: 'invalid_transition', detail: 'Cannot go from closed to pending.' })).toBe(
      'Cannot go from closed to pending.',
    )
    expect(bulkRowMessage({ code: 'invalid_transition', detail: null })).toBe(
      copy.problems.bulk.invalidTransition,
    )
    expect(bulkRowMessage({ code: null, detail: null })).toBe(copy.problems.bulk.failed)
  })
})
