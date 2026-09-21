import { describe, expect, it } from 'vitest'
import { brandContrast, darkVariant, hexToOklch, tenantBrandCss } from './brand'
import { contrastRatio } from './contrast'

describe('hexToOklch', () => {
  it('converts sRGB hex to oklch and rejects anything else', () => {
    expect(hexToOklch('#ffffff')?.l).toBeCloseTo(1, 3)
    expect(hexToOklch('#000000')?.l).toBeCloseTo(0, 3)
    const blue = hexToOklch('#2563eb')
    expect(blue?.l).toBeCloseTo(0.546, 2)
    expect(blue?.h).toBeCloseTo(262.9, 0)
    for (const value of ['2563eb', '#2563e', '#2563ebff', 'blue', 'oklch(0.5 0.1 250)', '']) {
      expect(hexToOklch(value)).toBeNull()
    }
  })

  it('gives grey no hue', () => {
    expect(hexToOklch('#777777')).toMatchObject({ h: 0 })
    expect(hexToOklch('#777777')?.c).toBeLessThan(1e-6)
  })
})

describe('brandContrast', () => {
  it('picks the foreground with the higher ratio and checks it against 4.5:1', () => {
    const teal = brandContrast('#0f766e')
    expect(teal).toMatchObject({ foreground: '--neutral-0', passes: true })
    expect(teal?.ratio).toBeGreaterThan(4.5)

    const yellow = brandContrast('#ffdd00')
    expect(yellow).toMatchObject({ foreground: '--neutral-950', passes: true })
    expect(yellow?.onWhite).toBeLessThan(3)
  })

  it('fails a mid grey that neither white nor near-black text can sit on', () => {
    expect(brandContrast('#777777')).toMatchObject({ passes: false })
    expect(brandContrast('not a colour')).toBeNull()
  })

  it('keeps the dark-theme variant readable too', () => {
    const navy = hexToOklch('#1e3a8a')
    if (!navy) throw new Error('fixture')
    const dark = darkVariant(navy)
    expect(dark.l).toBe(0.7)
    expect(dark.h).toBe(navy.h)
    expect(darkVariant({ ...navy, l: 0.9 }).l).toBe(0.9)
    expect(brandContrast('#1e3a8a')?.darkRatio).toBeGreaterThan(4.5)
  })
})

describe('tenantBrandCss', () => {
  it('writes the four overridden tokens for both themes, scoped to data-tenant', () => {
    const css = tenantBrandCss('#0F766E')
    expect(css).toContain(
      ':root[data-tenant] { --primary: #0f766e; --primary-foreground: var(--neutral-0); --ring: #0f766e; --chart-1: #0f766e; }',
    )
    expect(css).toMatch(
      /:root\[data-tenant\]\[data-theme="dark"\], :root\[data-tenant\] \[data-theme="dark"\] \{ --primary: oklch\(0\.7 [\d.]+ [\d.]+\); --primary-foreground: var\(--neutral-950\);/,
    )
  })

  it('writes nothing without a valid colour', () => {
    expect(tenantBrandCss(null)).toBeNull()
    expect(tenantBrandCss(undefined)).toBeNull()
    expect(tenantBrandCss('teal')).toBeNull()
  })
})

describe('contrast of the dark variant', () => {
  it('never darkens a colour that is already light', () => {
    const light = hexToOklch('#93c5fd')
    if (!light) throw new Error('fixture')
    expect(contrastRatio(darkVariant(light), light)).toBeCloseTo(1, 5)
  })
})
