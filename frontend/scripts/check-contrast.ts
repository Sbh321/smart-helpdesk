/**
 * Token contrast check (docs/06-design-system/tokens.md §Contrast pairs to verify).
 *
 * Reads src/styles/tokens.css, resolves the light (:root) and dark ([data-theme="dark"]) semantic tokens
 * down to oklch primitives, and prints the WCAG contrast for every pair. Exits 1 when a pair is
 * below its requirement: 4.5:1 for text, 3:1 for UI boundaries and chart series.
 *
 * Run from frontend/: node --experimental-strip-types scripts/check-contrast.ts
 */
import { readFileSync } from 'node:fs'
import { contrastRatio, type Oklch, oklchToLinearSrgb, parseOklch } from '../src/lib/theme/contrast.ts'

type Theme = 'light' | 'dark'
type Tokens = Map<string, string>

interface Pair {
  label: string
  foreground: string
  background: string
  minimum: number
}

const TEXT = 4.5
const NON_TEXT = 3

const tokensPath = new URL('../src/styles/tokens.css', import.meta.url)

/** Collects custom properties from top-level rules whose selector list matches `wanted`. Nested at-rules are skipped. */
function collectDeclarations(css: string, wanted: (selector: string) => boolean): Tokens {
  const source = css.replace(/\/\*[\s\S]*?\*\//g, '')
  const tokens: Tokens = new Map()
  let depth = 0
  let prelude = ''
  let body = ''
  let capture = false

  for (const char of source) {
    if (char === '{') {
      if (depth === 0) {
        const selectors = prelude.trim()
        capture = !selectors.startsWith('@') && selectors.split(',').some((s) => wanted(s.trim()))
        body = ''
      }
      depth += 1
      prelude = ''
    } else if (char === '}') {
      depth -= 1
      if (depth === 0) {
        if (capture) {
          for (const match of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
            const [, name = '', value = ''] = match
            tokens.set(name, value.trim())
          }
        }
        capture = false
        prelude = ''
      }
    } else if (depth === 0) {
      // A top-level `;` ends a statement such as @custom-variant; start the next prelude afresh.
      prelude = char === ';' ? '' : prelude + char
    } else if (depth === 1) {
      body += char
    }
  }
  return tokens
}

function resolve(tokens: Tokens, name: string, seen: string[] = []): string {
  if (seen.includes(name)) {
    throw new Error(`Circular token reference: ${[...seen, name].join(' → ')}`)
  }
  const value = tokens.get(name)
  if (value === undefined) {
    throw new Error(`Token ${name} is not defined`)
  }
  const reference = /^var\((--[\w-]+)\)$/.exec(value)
  return reference?.[1] ? resolve(tokens, reference[1], [...seen, name]) : value
}

function themeTokens(css: string, theme: Theme): Tokens {
  const light = collectDeclarations(css, (selector) => selector === ':root')
  if (theme === 'light') {
    return light
  }
  const dark = collectDeclarations(css, (selector) => selector === '[data-theme="dark"]')
  return new Map([...light, ...dark])
}

const onPair = (token: string, minimum = TEXT): Pair => ({
  label: `${token}-foreground on ${token}`,
  foreground: `--${token}-foreground`,
  background: `--${token}`,
  minimum,
})

const pairs: Pair[] = [
  {
    label: 'foreground on background',
    foreground: '--foreground',
    background: '--background',
    minimum: TEXT,
  },
  {
    label: 'muted-foreground on background',
    foreground: '--muted-foreground',
    background: '--background',
    minimum: TEXT,
  },
  {
    label: 'muted-foreground on muted',
    foreground: '--muted-foreground',
    background: '--muted',
    minimum: TEXT,
  },
  {
    label: 'muted-foreground on surface',
    foreground: '--muted-foreground',
    background: '--surface',
    minimum: TEXT,
  },
  onPair('surface'),
  onPair('surface-elevated'),
  onPair('primary'),
  onPair('secondary'),
  onPair('accent'),
  onPair('destructive'),
  onPair('warning'),
  onPair('success'),
  onPair('info'),
  onPair('sidebar'),
  ...['open', 'assigned', 'in-progress', 'pending', 'resolved', 'closed'].map((status) =>
    onPair(`status-${status}`),
  ),
  ...['p1', 'p2', 'p3', 'p4'].map((priority) => onPair(`priority-${priority}`)),
  ...['ok', 'warning', 'breached', 'paused'].map((state) => onPair(`sla-${state}`)),
  // Semantic colours used as text (text-primary links, text-destructive errors, text-sla-breached countdowns).
  ...[
    'primary',
    'destructive',
    'warning',
    'success',
    'info',
    'sla-ok',
    'sla-warning',
    'sla-breached',
  ].flatMap((token) =>
    ['background', 'surface'].map((canvas) => ({
      label: `${token} text on ${canvas}`,
      foreground: `--${token}`,
      background: `--${canvas}`,
      minimum: TEXT,
    })),
  ),
  { label: 'input on background', foreground: '--input', background: '--background', minimum: NON_TEXT },
  { label: 'ring on background', foreground: '--ring', background: '--background', minimum: NON_TEXT },
  ...[1, 2, 3, 4, 5, 6].map((n) => ({
    label: `chart-${n} on surface`,
    foreground: `--chart-${n}`,
    background: '--surface',
    minimum: NON_TEXT,
  })),
]

function main(): number {
  const css = readFileSync(tokensPath, 'utf8')
  const themes: Theme[] = ['light', 'dark']
  const resolved = new Map(themes.map((theme) => [theme, themeTokens(css, theme)]))
  const failures: string[] = []
  const gamutNotes = new Set<string>()

  const colour = (theme: Theme, name: string): Oklch => {
    const value = resolve(resolved.get(theme) ?? new Map(), name)
    const parsed = parseOklch(value)
    if (parsed?.alpha !== 1) {
      throw new Error(`${theme}: ${name} must resolve to an opaque oklch() colour, got "${value}"`)
    }
    if (oklchToLinearSrgb(parsed).clipped) {
      gamutNotes.add(`${theme} ${name} (${value})`)
    }
    return parsed
  }
  const ratio = (theme: Theme, pair: Pair): number =>
    contrastRatio(colour(theme, pair.foreground), colour(theme, pair.background))

  const rows = pairs.map((pair) => {
    const cells = themes.map((theme) => {
      const value = ratio(theme, pair)
      const ok = value >= pair.minimum
      if (!ok) {
        failures.push(`${theme}: ${pair.label} is ${value.toFixed(2)}:1 (needs ${pair.minimum}:1)`)
      }
      return `${value.toFixed(2).padStart(6)}${ok ? '  ' : ' ✗'}`
    })
    return `${pair.label.padEnd(52)}${cells.join('  ')}   ${pair.minimum}`
  })

  console.log(`${'Pair'.padEnd(52)}${'Light'.padStart(8)}  ${'Dark'.padStart(8)}   Min`)
  console.log(rows.join('\n'))
  if (gamutNotes.size > 0) {
    console.log(`\nOutside sRGB, clipped for the check: ${[...gamutNotes].join(', ')}`)
  }
  if (failures.length > 0) {
    console.error(`\n${failures.length} pair(s) below requirement:\n  ${failures.join('\n  ')}`)
    return 1
  }
  console.log(`\nAll ${pairs.length * themes.length} pairs meet their requirement.`)
  return 0
}

process.exitCode = main()
