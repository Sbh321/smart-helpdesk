import { contrastRatio, type Oklch, pickForeground } from './contrast'

/**
 * Tenant branding (docs/06-design-system/tokens.md §Tenant branding): one `#rrggbb` primary colour from
 * `me.tenant.branding.primary` becomes the `<style id="tenant-brand">` overrides, scoped to
 * `:root[data-tenant]`. Pure functions; `theme-dom.ts` writes the result into the document.
 * MVP-SHORTCUT: hex only, as the API validates; V1: V1-PL-14 oklch() colours.
 */
export const HEX_COLOR_PATTERN = /^#[0-9a-fA-F]{6}$/

/** WCAG AA for text: the ratio the foreground on the primary colour has to reach. */
export const MIN_BRAND_CONTRAST = 4.5

/** Dark surfaces need a light primary: the dark variant has at least this oklch lightness (themes.md). */
export const DARK_MIN_LIGHTNESS = 0.7

/** `--neutral-0` and `--neutral-950` of tokens.css: the two foregrounds a primary colour can carry. */
const FOREGROUNDS = [
  { token: '--neutral-0', color: { l: 1, c: 0, h: 0, alpha: 1 } },
  { token: '--neutral-950', color: { l: 0.145, c: 0.01, h: 250, alpha: 1 } },
] as const

export type ForegroundToken = (typeof FOREGROUNDS)[number]['token']

function toLinear(channel: number): number {
  return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
}

/** `#rrggbb` → oklch (the inverse of `oklchToLinearSrgb`); null for anything else. */
export function hexToOklch(hex: string): Oklch | null {
  if (!HEX_COLOR_PATTERN.test(hex)) {
    return null
  }
  const r = toLinear(Number.parseInt(hex.slice(1, 3), 16) / 255)
  const g = toLinear(Number.parseInt(hex.slice(3, 5), 16) / 255)
  const b = toLinear(Number.parseInt(hex.slice(5, 7), 16) / 255)

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b)
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b)
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b)

  const lightness = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s
  const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s
  const bAxis = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s

  const chroma = Math.hypot(a, bAxis)
  const hue = chroma < 1e-6 ? 0 : ((Math.atan2(bAxis, a) * 180) / Math.PI + 360) % 360
  return { l: lightness, c: chroma, h: hue, alpha: 1 }
}

/** The same hue and chroma, light enough for dark surfaces. */
export function darkVariant(color: Oklch): Oklch {
  return { ...color, l: Math.max(color.l, DARK_MIN_LIGHTNESS) }
}

/** The better of the two foregrounds on a primary colour, and the contrast it reaches. */
export function brandForeground(primary: Oklch): { token: ForegroundToken; ratio: number } {
  const [first, ...rest] = FOREGROUNDS.map((candidate) => candidate.color)
  const best = pickForeground(primary, [first as Oklch, ...rest])
  const token = FOREGROUNDS.find((candidate) => candidate.color === best.color)?.token ?? '--neutral-0'
  return { token, ratio: best.ratio }
}

export interface BrandContrast {
  /** Contrast of the chosen foreground on the colour as entered (light theme). */
  ratio: number
  foreground: ForegroundToken
  /** The same for the derived dark-theme variant. */
  darkRatio: number
  /** The colour against a white page: below 3:1 links and focus rings in the brand colour are hard to see. */
  onWhite: number
  passes: boolean
}

/** What the branding form shows and enforces for a candidate colour; null when it is not `#rrggbb`. */
export function brandContrast(hex: string): BrandContrast | null {
  const color = hexToOklch(hex)
  if (!color) {
    return null
  }
  const light = brandForeground(color)
  return {
    ratio: light.ratio,
    foreground: light.token,
    darkRatio: brandForeground(darkVariant(color)).ratio,
    onWhite: contrastRatio(color, FOREGROUNDS[0].color),
    passes: light.ratio >= MIN_BRAND_CONTRAST,
  }
}

function formatOklch({ l, c, h }: Oklch): string {
  const round = (value: number, digits: number) => Number(value.toFixed(digits))
  return `oklch(${round(l, 4)} ${round(c, 4)} ${round(h, 2)})`
}

function declarations(primary: string, foreground: ForegroundToken): string {
  return `--primary: ${primary}; --primary-foreground: var(${foreground}); --ring: ${primary}; --chart-1: ${primary};`
}

/**
 * The content of `<style id="tenant-brand">`; null (no element, no `data-tenant`) when the workspace has
 * no primary colour or the value is not a hex colour. The dark rule is more specific than both the
 * light rule and `[data-theme="dark"]` of tokens.css, so it also wins inside a nested theme island.
 */
export function tenantBrandCss(primary: string | null | undefined): string | null {
  const color = typeof primary === 'string' ? hexToOklch(primary) : null
  if (!color || typeof primary !== 'string') {
    return null
  }
  const dark = darkVariant(color)
  return [
    `:root[data-tenant] { ${declarations(primary.toLowerCase(), brandForeground(color).token)} }`,
    `:root[data-tenant][data-theme="dark"], :root[data-tenant] [data-theme="dark"] { ${declarations(formatOklch(dark), brandForeground(dark).token)} }`,
  ].join('\n')
}
