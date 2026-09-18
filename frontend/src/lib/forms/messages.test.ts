import { describe, expect, it } from 'vitest'
import { ApiError } from '@/lib/api/errors'
import { mergeMessages, messagesOf, serverFieldErrors } from './messages'

describe('form messages', () => {
  it('flattens validator issues and strings', () => {
    expect(messagesOf([{ message: 'Enter a name.' }, 'Too long', null, { other: 1 }])).toEqual([
      'Enter a name.',
      'Too long',
    ])
  })

  it('merges client and server messages without repeats', () => {
    expect(mergeMessages([{ message: 'A' }], ['A', 'B'])).toEqual(['A', 'B'])
  })

  it('maps 422 field errors onto form fields, collapsing array members', () => {
    const error = new ApiError({
      status: 422,
      code: 'validation_failed',
      title: 'Invalid',
      fieldErrors: {
        email: ['The email has already been taken.'],
        'tags.0': ['Too long.'],
        'tags.1': ['Too long.'],
      },
    })
    expect(serverFieldErrors(error)).toEqual({
      email: ['The email has already been taken.'],
      tags: ['Too long.'],
    })
  })

  it('has no field errors for other failures', () => {
    expect(serverFieldErrors(new ApiError({ status: 500, code: 'server_error', title: 'Oops' }))).toEqual({})
    expect(serverFieldErrors(new Error('boom'))).toEqual({})
  })
})
