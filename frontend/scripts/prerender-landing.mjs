// Prerenders the landing site (M5-04): renders `src/landing/render.tsx` (built for SSR into
// `dist-landing-ssr/`) and writes the HTML, title and description into `dist-landing/index.html`.
import { readFile, rm, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const { render } = await import(`${root}/dist-landing-ssr/render.js`)
const { html, title, description } = render()

const escapeAttribute = (value) =>
  value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;')
const file = `${root}/dist-landing/index.html`
const page = (await readFile(file, 'utf8'))
  .replace('<!--landing-html-->', html)
  .replaceAll('<!--landing-title-->', escapeAttribute(title))
  .replaceAll('<!--landing-description-->', escapeAttribute(description))

if (page.includes('<!--landing-')) throw new Error('An unfilled landing placeholder is left in index.html')
await writeFile(file, page)
await rm(`${root}/dist-landing-ssr`, { recursive: true, force: true })
console.log(`prerendered landing page: ${(page.length / 1024).toFixed(1)} KiB of HTML`)
