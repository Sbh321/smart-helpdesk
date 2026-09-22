import { describe, expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { formatMailbox, senderFormSchema } from './schemas'

const rules = copy.mailSettings.validation

describe('senderFormSchema', () => {
  test('accepts a display name or nothing', () => {
    expect(senderFormSchema.safeParse({ sender_name: 'Acme Customer Care' }).success).toBe(true)
    expect(senderFormSchema.safeParse({ sender_name: '' }).success).toBe(true)
  })

  test.each([
    ['Acme\nBcc: x@evil.test', rules.senderNameCharacters],
    ['Acme <ceo@acme.test>', rules.senderNameCharacters],
    ['Acme "Support"', rules.senderNameCharacters],
    ['support@paypal.com', rules.senderNameCharacters],
    ['a'.repeat(81), rules.senderNameLength],
  ])('refuses %j', (name, message) => {
    const result = senderFormSchema.safeParse({ sender_name: name })
    expect(result.success).toBe(false)
    expect(result.error?.issues.map((issue) => issue.message)).toContain(message)
  })
})

test('formatMailbox writes the sender as mail clients do', () => {
  expect(formatMailbox({ name: 'Acme Support', address: 'support+acme@shp.localhost' })).toBe(
    'Acme Support <support+acme@shp.localhost>',
  )
})
