/**
 * Design-token discipline gate (docs/06-design-system/principles.md D1, roadmap M4-01).
 *
 * Principle D1 says no literal colour, radius or layer value reaches a component: every visual value
 * comes from a token in `styles/tokens.css`. That held by convention until now; this script makes it a
 * build failure. It reads the source rather than the rendered CSS, so it also catches values written
 * into `class` strings that Tailwind would happily accept.
 *
 * Escape hatch: a file may carry `// design-tokens-allow: <reason>` on the line above the value, for
 * the rare case where a literal is the input rather than the styling (a colour picker's default).
 *
 *   node --experimental-strip-types scripts/check-design-tokens.ts
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const ROOT = new URL('..', import.meta.url).pathname
const SRC = join(ROOT, 'src')

/** Tailwind's own palette: using it bypasses the semantic tokens (bg-gray-100, text-red-500, …). */
const PALETTE =
  /\b(?:bg|text|border|ring|outline|fill|stroke|from|via|to|decoration|shadow|divide|accent|caret)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(?:50|\d{3})\b/

/** A colour written into a class string, e.g. `bg-[#2563eb]` or `text-[rgb(0,0,0)]`. The utility prefix
 *  is required, so prose such as "Re: [#1001] Printer offline" is not a match. */
const ARBITRARY_COLOUR = /\b[a-z][a-z-]*-\[\s*(?:#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(|oklch\()/

/** An arbitrary radius or z-index with a raw length: `rounded-[6px]`, `z-[999]`. Token-derived
 *  expressions such as `rounded-[calc(var(--radius)-3px)]` are fine, because they read a token. */
const ARBITRARY_RADIUS = /\brounded(?:-[a-z]+)?-\[(?![^\]]*var\(--)[^\]]*(?:px|rem)[^\]]*\]/
const ARBITRARY_Z = /\bz-\[(?![^\]]*var\(--)[^\]]*\d[^\]]*\]/

const CHECKS = [
  {
    name: 'Tailwind palette colour (use a semantic token: bg-surface, text-muted-foreground, …)',
    re: PALETTE,
  },
  { name: 'colour literal in a class (define a token in styles/tokens.css instead)', re: ARBITRARY_COLOUR },
  {
    name: 'arbitrary radius (use rounded-xs|badge|control|card|modal, or a var(--radius…) expression)',
    re: ARBITRARY_RADIUS,
  },
  { name: 'arbitrary z-index (use the layer utilities: z-10 … z-50 as the components do)', re: ARBITRARY_Z },
] as const

const ALLOW = /design-tokens-allow:/

function* files(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) {
      yield* files(path)
    } else if (/\.(tsx|ts|css)$/.test(entry) && !/\.(test|stories)\./.test(entry) && entry !== 'tokens.css') {
      yield path
    }
  }
}

const problems: string[] = []
for (const path of files(SRC)) {
  const lines = readFileSync(path, 'utf8').split('\n')
  lines.forEach((line, index) => {
    if (ALLOW.test(line) || (index > 0 && ALLOW.test(lines[index - 1] ?? ''))) return
    for (const check of CHECKS) {
      if (check.re.test(line)) {
        problems.push(`${relative(ROOT, path)}:${index + 1}  ${check.name}\n    ${line.trim().slice(0, 120)}`)
      }
    }
  })
}

if (problems.length > 0) {
  console.error(`Design-token violations (${problems.length}):\n`)
  for (const problem of problems) console.error(problem)
  console.error('\nSee docs/06-design-system/principles.md D1 and tokens.md.')
  process.exit(1)
}
console.log('Design tokens: no literal colours, radii or layers outside styles/tokens.css.')
