import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'
import { buildSidebar } from './sidebar.mts'

/**
 * The platform documentation site (roadmap M5-05): this folder rendered as it is, for platform super
 * admins on `platform-docs.<domain>` behind the platform sign-in (M5-06). The Markdown stays the source
 * of truth and reads the same on GitHub: the sidebar comes from the folder tree, titles from headings,
 * and links that leave `docs/` go to the repository.
 */
const REPOSITORY = 'https://github.com/Sbh321/smart-helpdesk'
const DOCS = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

/** A folder with no README.md has no page of its own on the site; it is linked on GitHub instead. */
function isFolderWithoutOverview(resolved: string): boolean {
  const full = path.join(DOCS, resolved)
  return fs.existsSync(full) && fs.statSync(full).isDirectory() && !fs.existsSync(path.join(full, 'README.md'))
}

export default defineConfig({
  title: 'Smart Helpdesk',
  titleTemplate: ':title · Platform docs',
  description: 'How Smart Helpdesk is designed, built and run: architecture, domain, algorithms, API, database, infrastructure and operations.',
  lang: 'en-GB',
  cleanUrls: true,
  lastUpdated: false,
  appearance: true,
  // Platform docs are for signed-in platform admins only (M5-06); keep them out of search engines too.
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    ['meta', { name: 'robots', content: 'noindex, nofollow' }],
  ],
  srcExclude: ['superpowers/**', 'node_modules/**'],
  // README.md is each folder's overview page.
  rewrites: { 'README.md': 'index.md', ':dir/README.md': ':dir/index.md' },
  // A link to a page that does not exist fails the build; local development addresses are the exception.
  ignoreDeadLinks: [/^https?:\/\/localhost/, /^https?:\/\/127\.0\.0\.1/, /\.localhost/],

  markdown: {
    theme: { light: 'github-light', dark: 'github-dark' },
    config(md) {
      // Links that leave docs/ (roadmap, infra, code) point at the file in the repository instead.
      md.core.ruler.push('repository_links', (state) => {
        const relativePath: string = state.env.relativePath ?? ''
        for (const token of state.tokens) {
          for (const child of token.children ?? []) {
            if (child.type !== 'link_open') continue
            const href = child.attrGet('href') ?? ''
            if (!href || /^[a-z][a-z0-9+.-]*:|^#|^\//i.test(href)) continue
            const [target, hash = ''] = href.split('#')
            const resolved = path.posix.normalize(path.posix.join(path.posix.dirname(relativePath), target))
            if (resolved.startsWith('../') || isFolderWithoutOverview(resolved)) {
              const inRepository = path.posix.normalize(`docs/${resolved}`)
              const kind = isFolderWithoutOverview(resolved) || target.endsWith('/') ? 'tree' : 'blob'
              child.attrSet('href', `${REPOSITORY}/${kind}/main/${inRepository}${hash ? `#${hash}` : ''}`)
              child.attrSet('target', '_blank')
              child.attrSet('rel', 'noopener noreferrer')
            }
          }
        }
      })
      // Inline code is text, never a Vue template (`{{json .State.Health}}` in the runbooks).
      const codeInline = md.renderer.rules.code_inline
      md.renderer.rules.code_inline = (tokens, index, options, env, self) =>
        (codeInline ? codeInline(tokens, index, options, env, self) : self.renderToken(tokens, index, options)).replace(
          '<code',
          '<code v-pre',
        )
      // ```mermaid fences render in the browser through <Mermaid> (theme/Mermaid.vue).
      const fence = md.renderer.rules.fence
      md.renderer.rules.fence = (tokens, index, options, env, self) => {
        const token = tokens[index]
        if (token.info.trim() === 'mermaid') {
          return `<Mermaid code="${encodeURIComponent(token.content)}" />`
        }
        return fence ? fence(tokens, index, options, env, self) : self.renderToken(tokens, index, options)
      }
    },
  },

  themeConfig: {
    siteTitle: 'Platform docs',
    logo: '/favicon.svg',
    nav: [
      { text: 'Overview', link: '/' },
      { text: 'Architecture', link: '/03-architecture/overview' },
      { text: 'Decisions', link: '/adr/' },
      { text: 'Operations', link: '/11-operations/runbooks' },
      { text: 'Repository', link: REPOSITORY },
    ],
    sidebar: buildSidebar(),
    outline: { level: [2, 3], label: 'On this page' },
    search: { provider: 'local' },
    docFooter: { prev: 'Previous', next: 'Next' },
    editLink: { pattern: `${REPOSITORY}/blob/main/docs/:path`, text: 'View this page in the repository' },
    darkModeSwitchLabel: 'Appearance',
    sidebarMenuLabel: 'Documentation',
    returnToTopLabel: 'Back to top',
  },

  vite: {
    optimizeDeps: { include: ['mermaid'] },
    build: { chunkSizeWarningLimit: 2000 },
  },
})
