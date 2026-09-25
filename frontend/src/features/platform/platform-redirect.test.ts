import { describe, expect, it } from 'vitest'
import { platformRedirect } from './components/platform-login-form'

describe('platformRedirect', () => {
  it.each([
    ['/platform/docs', '/platform/docs'],
    ['/platform/tenants?page=2', '/platform/tenants?page=2'],
    ['/platform/login', undefined],
    ['/acme/tickets', undefined],
    ['//evil.test/platform/docs', undefined],
    ['https://evil.test/platform/docs', undefined],
    [undefined, undefined],
  ])('follows %j to %j', (input, expected) => {
    expect(platformRedirect(input)).toBe(expected)
  })
})
