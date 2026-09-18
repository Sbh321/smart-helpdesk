/**
 * WCAG 2.2 contrast for CSS `oklch()` colours, without dependencies.
 *
 * oklch → OKLab → linear sRGB (Björn Ottosson's matrices, as in CSS Color 4) → relative luminance.
 * Out-of-gamut colours are clipped per channel. That is an approximation of CSS gamut mapping,
 * which is close enough for the token pairs.
 * Used by `scripts/check-contrast.ts` and, later, to pick a foreground for a tenant primary colour
 * (docs/06-design-system/tokens.md §Tenant branding).
 *
 * This module must stay import-free: Node runs it directly with `--experimental-strip-types`.
 */

export interface Oklch {
  l: number
  c: number
  h: number
  alpha: number
}

export interface LinearRgb {
  r: number
  g: number
  b: number
  /** True when at least one channel was outside [0, 1] before clipping. */
  clipped: boolean
}

const OKLCH_PATTERN = /^oklch\(\s*([\d.]+%?)\s+([\d.]+%?)\s+([\d.]+)(?:deg)?\s*(?:\/\s*([\d.]+%?)\s*)?\)$/i

function parseNumber(raw: string, percentScale: number): number {
  return raw.endsWith('%') ? (Number.parseFloat(raw) / 100) * percentScale : Number.parseFloat(raw)
}

/** Parses `oklch(L C H)` or `oklch(L C H / A)`; returns null for anything else. */
export function parseOklch(value: string): Oklch | null {
  const match = OKLCH_PATTERN.exec(value.trim())
  if (!match) {
    return null
  }
  const [, l = '0', c = '0', h = '0', alpha] = match
  return {
    l: parseNumber(l, 1),
    c: parseNumber(c, 0.4),
    h: Number.parseFloat(h),
    alpha: alpha === undefined ? 1 : parseNumber(alpha, 1),
  }
}

const clamp01 = (value: number): number => Math.min(1, Math.max(0, value))

/** Converts oklch to linear-light sRGB, clipping each channel to [0, 1]. */
export function oklchToLinearSrgb({ l, c, h }: Oklch): LinearRgb {
  const hue = (h * Math.PI) / 180
  const a = c * Math.cos(hue)
  const b = c * Math.sin(hue)

  const lp = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3
  const mp = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3
  const sp = (l - 0.0894841775 * a - 1.291485548 * b) ** 3

  const red = 4.0767416621 * lp - 3.3077115913 * mp + 0.2309699292 * sp
  const green = -1.2684380046 * lp + 2.6097574011 * mp - 0.3413193965 * sp
  const blue = -0.0041960863 * lp - 0.7034186147 * mp + 1.707614701 * sp

  const epsilon = 1e-4
  const clipped = [red, green, blue].some((channel) => channel < -epsilon || channel > 1 + epsilon)
  return { r: clamp01(red), g: clamp01(green), b: clamp01(blue), clipped }
}

/** WCAG relative luminance. The WCAG formula linearises gamma-encoded sRGB first; our input is already linear. */
export function relativeLuminance(color: Oklch): number {
  const { r, g, b } = oklchToLinearSrgb(color)
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/** WCAG contrast ratio (1–21) between two opaque colours. */
export function contrastRatio(first: Oklch, second: Oklch): number {
  const a = relativeLuminance(first)
  const b = relativeLuminance(second)
  const [lighter, darker] = a >= b ? [a, b] : [b, a]
  return (lighter + 0.05) / (darker + 0.05)
}

/**
 * Picks the candidate foreground with the higher contrast against a background,
 * for example a tenant primary colour against `--neutral-0` and `--neutral-950`.
 */
export function pickForeground(
  background: Oklch,
  candidates: readonly [Oklch, ...Oklch[]],
): { color: Oklch; ratio: number } {
  let best = { color: candidates[0], ratio: contrastRatio(background, candidates[0]) }
  for (const candidate of candidates.slice(1)) {
    const ratio = contrastRatio(background, candidate)
    if (ratio > best.ratio) {
      best = { color: candidate, ratio }
    }
  }
  return best
}
