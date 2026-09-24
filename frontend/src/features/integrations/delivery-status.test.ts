import { describe, expect, test } from 'vitest'
import { canRetryDelivery, deliveryErrorText, deliveryStatus } from './delivery-status'

describe('deliveryStatus (M4-11)', () => {
  test('a failed delivery with a next attempt is retrying; without one it has failed', () => {
    expect(deliveryStatus({ state: 'failed', next_attempt_at: '2026-09-23T10:00:00Z' })).toBe('retrying')
    expect(deliveryStatus({ state: 'failed', next_attempt_at: null })).toBe('failed')
  })

  test('the other states map one to one', () => {
    expect(deliveryStatus({ state: 'pending', next_attempt_at: null })).toBe('queued')
    expect(deliveryStatus({ state: 'succeeded', next_attempt_at: null })).toBe('delivered')
    expect(deliveryStatus({ state: 'dead', next_attempt_at: null })).toBe('gave_up')
  })

  test('manual retry only where the API accepts it', () => {
    expect(canRetryDelivery({ state: 'dead' }, true)).toBe(true)
    expect(canRetryDelivery({ state: 'failed' }, true)).toBe(true)
    expect(canRetryDelivery({ state: 'failed' }, false)).toBe(false)
    expect(canRetryDelivery({ state: 'succeeded' }, true)).toBe(false)
    expect(canRetryDelivery({ state: 'pending' }, true)).toBe(false)
  })
})

describe('deliveryErrorText', () => {
  test('stored codes read as sentences, with the status and the rejection reason', () => {
    expect(deliveryErrorText('http_status', 503)).toBe('Your endpoint answered HTTP 503.')
    expect(deliveryErrorText('timeout', null)).toBe('Your endpoint did not answer within 10 seconds.')
    expect(deliveryErrorText('url_rejected: private address', null)).toBe(
      'The URL was refused before sending (private address)',
    )
  })

  test('an unknown code is shown as sent; no error is no text', () => {
    expect(deliveryErrorText('something_new', null)).toBe('something_new')
    expect(deliveryErrorText(null, 200)).toBeNull()
  })
})
