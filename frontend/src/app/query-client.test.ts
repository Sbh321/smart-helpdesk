import { describe, expect, test } from 'vitest'
import { ApiError } from '@/lib/api/errors'
import { shouldRetry } from './query-client'

const apiError = (status: number) => new ApiError({ status, code: 'x', title: 'x' })

describe('shouldRetry', () => {
  test('never retries a client error that will not change', () => {
    for (const status of [400, 401, 403, 404, 409, 422]) expect(shouldRetry(0, apiError(status))).toBe(false)
  })

  test('retries server, network, timeout and rate-limit errors twice', () => {
    for (const error of [apiError(500), apiError(0), apiError(408), apiError(429), new Error('boom')]) {
      expect(shouldRetry(0, error)).toBe(true)
      expect(shouldRetry(1, error)).toBe(true)
      expect(shouldRetry(2, error)).toBe(false)
    }
  })
})
