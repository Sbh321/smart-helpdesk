/**
 * The landing site's only script (M5-04): the page is static HTML and works without it. It shows and
 * wires the light/dark switch, and marks the header once the page has scrolled.
 */
const THEME_KEY = 'sh.theme'
const root = document.documentElement

const toggle = document.querySelector<HTMLButtonElement>('[data-theme-toggle]')
if (toggle) {
  const sync = () => toggle.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'))
  toggle.hidden = false
  sync()
  toggle.addEventListener('click', () => {
    const next = root.dataset.theme === 'dark' ? 'light' : 'dark'
    root.dataset.theme = next
    try {
      window.localStorage.setItem(THEME_KEY, next)
    } catch {
      // Storage refused: the choice lasts for this visit only.
    }
    sync()
  })
}

const header = document.querySelector<HTMLElement>('[data-landing-header]')
if (header) {
  const onScroll = () => header.toggleAttribute('data-scrolled', window.scrollY > 8)
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
}

// Close the small-screen menu after following one of its links.
for (const link of document.querySelectorAll<HTMLAnchorElement>('details a')) {
  link.addEventListener('click', () => link.closest('details')?.removeAttribute('open'))
}

for (const year of document.querySelectorAll('[data-year]')) {
  year.textContent = String(new Date().getFullYear())
}

// Development only: the page has not been prerendered yet, so render it here.
if (import.meta.env.DEV) {
  const mount = document.getElementById('landing')
  if (mount && !mount.firstElementChild) {
    void import('./dev-render').then(({ renderInto }) => renderInto(mount))
  }
}
