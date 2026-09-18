import { describe, expect, it } from 'vitest'
import { contrastRatio, type Oklch, oklchToLinearSrgb, parseOklch, pickForeground } from './contrast'

const white: Oklch = { l: 1, c: 0, h: 0, alpha: 1 }
const black: Oklch = { l: 0, c: 0, h: 0, alpha: 1 }

describe('parseOklch', () => {
  it('parses numbers, percentages, a deg unit and alpha', () => {
    expect(parseOklch('oklch(0.546 0.19 258)')).toEqual({ l: 0.546, c: 0.19, h: 258, alpha: 1 })
    expect(parseOklch('oklch(50% 50% 90deg / 60%)')).toEqual({ l: 0.5, c: 0.2, h: 90, alpha: 0.6 })
    expect(parseOklch(' oklch(0.145 0.01 250 / 0.6) ')).toEqual({ l: 0.145, c: 0.01, h: 250, alpha: 0.6 })
  })

  it('rejects other colour syntaxes', () => {
    expect(parseOklch('#ffffff')).toBeNull()
    expect(parseOklch('var(--neutral-0)')).toBeNull()
    expect(parseOklch('oklch(0.5 0.1)')).toBeNull()
  })
})

describe('oklchToLinearSrgb', () => {
  it('maps white and black to the sRGB extremes', () => {
    const light = oklchToLinearSrgb(white)
    expect(light.r).toBeCloseTo(1, 4)
    expect(light.g).toBeCloseTo(1, 4)
    expect(light.b).toBeCloseTo(1, 4)
    expect(light.clipped).toBe(false)
    expect(oklchToLinearSrgb(black)).toEqual({ r: 0, g: 0, b: 0, clipped: false })
  })

  it('matches a known conversion: oklch(0.628 0.2577 29.23) is sRGB red', () => {
    const red = oklchToLinearSrgb({ l: 0.628, c: 0.2577, h: 29.23, alpha: 1 })
    expect(red.r).toBeCloseTo(1, 2)
    expect(red.g).toBeCloseTo(0, 2)
    expect(red.b).toBeCloseTo(0, 2)
  })

  it('flags and clips colours outside sRGB', () => {
    const vivid = oklchToLinearSrgb({ l: 0.7, c: 0.37, h: 145, alpha: 1 })
    expect(vivid.clipped).toBe(true)
    for (const channel of [vivid.r, vivid.g, vivid.b]) {
      expect(channel).toBeGreaterThanOrEqual(0)
      expect(channel).toBeLessThanOrEqual(1)
    }
  })
})

describe('contrastRatio', () => {
  it('is 21:1 for black on white, symmetric, and 1:1 for equal colours', () => {
    expect(contrastRatio(black, white)).toBeCloseTo(21, 5)
    expect(contrastRatio(white, black)).toBeCloseTo(21, 5)
    expect(contrastRatio(white, white)).toBe(1)
  })

  it('matches the WCAG value for #767676 on white (4.54:1)', () => {
    // #767676 is oklch(0.5658 0 0).
    expect(contrastRatio({ l: 0.5658, c: 0, h: 0, alpha: 1 }, white)).toBeCloseTo(4.54, 2)
  })
})

describe('pickForeground', () => {
  it('chooses the candidate with the higher contrast', () => {
    const lightBlue: Oklch = { l: 0.85, c: 0.08, h: 250, alpha: 1 }
    const darkBlue: Oklch = { l: 0.35, c: 0.1, h: 250, alpha: 1 }
    expect(pickForeground(lightBlue, [white, black]).color).toBe(black)
    expect(pickForeground(darkBlue, [black, white]).color).toBe(white)
    expect(pickForeground(darkBlue, [white]).ratio).toBeGreaterThan(4.5)
  })
})
