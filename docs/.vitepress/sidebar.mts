/**
 * Builds the sidebar from the folder tree (M5-05), so adding a page is adding a file.
 *
 * - Each top-level folder is a group, titled from `GROUP_TITLES`; nested folders are collapsed groups.
 * - A page's title is its first `# ` heading; `README.md` is the folder's overview and comes first.
 * - Pages sort by file name (ADRs by number); groups by their numeric prefix.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import type { DefaultTheme } from 'vitepress'

const DOCS = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

/** Folders that are not documentation: tooling, output, and agent working notes. */
export const SKIP = new Set(['node_modules', 'public', 'superpowers'])

const GROUP_TITLES: Record<string, string> = {
  '00-project': 'Project',
  '01-research': 'Research',
  '02-product': 'Product',
  '03-architecture': 'Architecture',
  '04-domain': 'Domain',
  '05-algorithms': 'Algorithms',
  '06-design-system': 'Design system',
  '07-api': 'API',
  '08-database': 'Database',
  '09-infrastructure': 'Infrastructure',
  '10-quality': 'Quality',
  '11-operations': 'Operations',
  '12-academic': 'Academic',
  adr: 'Decisions (ADRs)',
}

export function titleOf(file: string): string {
  const match = fs.readFileSync(file, 'utf8').match(/^#\s+(.+?)\s*$/m)
  return match ? match[1].replace(/`/g, '') : humanize(path.basename(file))
}

function humanize(name: string): string {
  return name
    .replace(/\.md$/, '')
    .replace(/^\d+-/, '')
    .replace(/-/g, ' ')
    .replace(/^\w/, (c) => c.toUpperCase())
}

function link(relative: string): string {
  return `/${relative.replace(/(^|\/)README\.md$/, '$1').replace(/\.md$/, '')}`
}

function walk(dir: string, relative: string): DefaultTheme.SidebarItem[] {
  const entries = fs
    .readdirSync(dir, { withFileTypes: true })
    .filter((entry) => !entry.name.startsWith('.') && !SKIP.has(entry.name))
    .sort((a, b) => {
      if (a.name === 'README.md') return -1
      if (b.name === 'README.md') return 1
      if (a.isDirectory() !== b.isDirectory()) return a.isDirectory() ? 1 : -1
      return a.name.localeCompare(b.name, 'en', { numeric: true })
    })

  const items: DefaultTheme.SidebarItem[] = []
  for (const entry of entries) {
    const full = path.join(dir, entry.name)
    const rel = relative ? `${relative}/${entry.name}` : entry.name
    if (entry.isDirectory()) {
      const children = walk(full, rel)
      if (children.length > 0) items.push({ text: humanize(entry.name), collapsed: true, items: children })
    } else if (entry.name.endsWith('.md')) {
      items.push({ text: entry.name === 'README.md' ? 'Overview' : titleOf(full), link: link(rel) })
    }
  }
  return items
}

export function buildSidebar(): DefaultTheme.SidebarItem[] {
  return fs
    .readdirSync(DOCS, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && entry.name in GROUP_TITLES)
    .sort((a, b) => (a.name === 'adr' ? 1 : b.name === 'adr' ? -1 : a.name.localeCompare(b.name)))
    .map((entry) => ({
      text: GROUP_TITLES[entry.name],
      collapsed: entry.name !== '00-project',
      items: walk(path.join(DOCS, entry.name), entry.name),
    }))
}
