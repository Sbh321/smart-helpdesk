/**
 * The product mark in one place (docs/06-design-system/brand.md): the lucide lifebuoy in white on a
 * rounded tile of the product blue, the same mark `BrandMark` draws in the apps. This script writes every
 * icon file from it, so the favicons of the landing site, the workspace app, the platform console, both
 * documentation sites and the API and monitoring hosts cannot drift apart:
 *
 *   favicon.svg           the icon every current browser uses
 *   favicon.ico           16, 32 and 48 px PNGs for older browsers and `/favicon.ico` requests
 *   apple-touch-icon.png  180 px, full bleed (the platform rounds it)
 *
 *   node scripts/brand-icons.mjs
 */
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
/** Every directory a host serves its root files from. */
const TARGETS = ['frontend/public', 'frontend/landing/public', 'docs/public', 'backend/public']

/** `--primary` of the light theme (`--blue-600`, oklch(0.546 0.19 258)) in sRGB. */
const BLUE = '#146bdc'
/** lucide `life-buoy`, 24-unit grid. */
const LIFEBUOY = [
  '<circle cx="12" cy="12" r="10"/>',
  '<path d="m4.93 4.93 4.24 4.24"/>',
  '<path d="m14.83 9.17 4.24-4.24"/>',
  '<path d="m14.83 14.83 4.24 4.24"/>',
  '<path d="m9.17 14.83-4.24 4.24"/>',
  '<circle cx="12" cy="12" r="4"/>',
].join('')

/**
 * The mark on a `size`-unit canvas. `rounded` gives the tile its corners (favicons); the touch icon is
 * full bleed. The glyph takes `glyph` of the side and a heavier stroke than in the app, so it still reads
 * at 16 px.
 */
function markSvg({ size = 32, rounded = true, glyph = 0.64 } = {}) {
  const scale = (size * glyph) / 24
  const offset = (size - size * glyph) / 2
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" role="img" aria-label="Smart Helpdesk">`,
    '<title>Smart Helpdesk</title>',
    `<rect width="${size}" height="${size}"${rounded ? ` rx="${(size * 7) / 32}"` : ''} fill="${BLUE}"/>`,
    `<g transform="translate(${offset} ${offset}) scale(${scale})" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">${LIFEBUOY}</g>`,
    '</svg>',
  ].join('')
}

async function render(page, svg, px) {
  await page.setViewportSize({ width: px, height: px })
  await page.setContent(
    `<html><body style="margin:0;background:transparent">${svg.replace(/width="\d+" height="\d+"/, `width="${px}" height="${px}"`)}</body></html>`,
  )
  return page.screenshot({ omitBackground: true, clip: { x: 0, y: 0, width: px, height: px } })
}

/** An ICO file whose entries are PNGs (supported by every browser since IE Vista-era). */
function ico(images) {
  const header = Buffer.alloc(6 + images.length * 16)
  header.writeUInt16LE(0, 0)
  header.writeUInt16LE(1, 2)
  header.writeUInt16LE(images.length, 4)
  let offset = header.length
  images.forEach(({ px, png }, index) => {
    const entry = 6 + index * 16
    header.writeUInt8(px >= 256 ? 0 : px, entry)
    header.writeUInt8(px >= 256 ? 0 : px, entry + 1)
    header.writeUInt16LE(1, entry + 4)
    header.writeUInt16LE(32, entry + 6)
    header.writeUInt32LE(png.length, entry + 8)
    header.writeUInt32LE(offset, entry + 12)
    offset += png.length
  })
  return Buffer.concat([header, ...images.map(({ png }) => png)])
}

const browser = await chromium.launch()
const page = await browser.newPage({ deviceScaleFactor: 1 })
const favicon = markSvg()
const icoImages = []
for (const px of [16, 32, 48]) icoImages.push({ px, png: await render(page, favicon, px) })
const touch = await render(page, markSvg({ size: 180, rounded: false, glyph: 0.56 }), 180)
await browser.close()

for (const target of TARGETS) {
  const dir = join(ROOT, target)
  mkdirSync(dir, { recursive: true })
  writeFileSync(join(dir, 'favicon.svg'), `${favicon}\n`)
  writeFileSync(join(dir, 'favicon.ico'), ico(icoImages))
  writeFileSync(join(dir, 'apple-touch-icon.png'), touch)
  console.log(`wrote ${target}/favicon.svg, favicon.ico, apple-touch-icon.png`)
}
