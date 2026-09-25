import { describe, expect, it } from 'vitest'
import { normaliseWorkspaceInput } from './workspace-input'

describe('normaliseWorkspaceInput', () => {
  it.each([
    ['acme', 'acme'],
    ['  Acme  ', 'acme'],
    ['https://app.shp.test/acme/login', 'acme'],
    ['app.shp.test/acme', 'acme'],
    ['http://APP.SHP.TEST/globex?x=1', 'globex'],
    ['shp.test/acme', 'acme'],
    ['acme/tickets', 'acme'],
    ['my workspace', 'my-workspace'],
    ['https://app.shp.test', ''],
    ['', ''],
  ])('turns %j into %j', (input, expected) => {
    expect(normaliseWorkspaceInput(input, 'shp.test')).toBe(expected)
  })
})
