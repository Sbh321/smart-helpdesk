import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { render } from 'vitest-browser-react'
import { copy } from '@/copy/en'
import { ThemeProvider } from '@/lib/theme'
import { ThemeToggle } from './theme-toggle'

/** A controllable `(prefers-color-scheme: dark)` media query. */
function fakeDarkSchemeQuery(initiallyDark: boolean) {
  const listeners = new Set<(event: MediaQueryListEvent) => void>()
  const query = {
    matches: initiallyDark,
    media: '(prefers-color-scheme: dark)',
    addEventListener: (_type: 'change', listener: (event: MediaQueryListEvent) => void) =>
      listeners.add(listener),
    removeEventListener: (_type: 'change', listener: (event: MediaQueryListEvent) => void) =>
      listeners.delete(listener),
  }
  vi.stubGlobal('matchMedia', () => query)
  return {
    setDark(dark: boolean) {
      query.matches = dark
      for (const listener of listeners) {
        listener({ matches: dark } as MediaQueryListEvent)
      }
    },
    listenerCount: () => listeners.size,
  }
}

const root = document.documentElement

function resetThemeState() {
  window.localStorage.clear()
  for (const name of ['data-theme', 'data-theme-choice', 'data-density']) {
    root.removeAttribute(name)
  }
}

beforeEach(resetThemeState)

afterEach(() => {
  vi.unstubAllGlobals()
  resetThemeState()
})

function renderToggle() {
  return render(
    <ThemeProvider>
      <ThemeToggle />
    </ThemeProvider>,
  )
}

test('switches between light, dark and system, updating <html> and localStorage', async () => {
  const scheme = fakeDarkSchemeQuery(false)
  const screen = await renderToggle()

  const group = screen.getByRole('group', { name: copy.theme.label })
  const light = group.getByRole('button', { name: copy.theme.light })
  const dark = group.getByRole('button', { name: copy.theme.dark })
  const system = group.getByRole('button', { name: copy.theme.system })

  // No stored choice: system, resolved against a light OS preference.
  await expect.element(system).toHaveAttribute('aria-pressed', 'true')
  await expect.poll(() => root.getAttribute('data-theme')).toBe('light')
  expect(root.getAttribute('data-density')).toBe('comfortable')

  await dark.click()
  await expect.element(dark).toHaveAttribute('aria-pressed', 'true')
  await expect.element(system).toHaveAttribute('aria-pressed', 'false')
  expect(root.getAttribute('data-theme')).toBe('dark')
  expect(root.getAttribute('data-theme-choice')).toBe('dark')
  expect(window.localStorage.getItem('sh.theme')).toBe('dark')
  await expect.element(screen.getByRole('status')).toHaveTextContent(copy.theme.announcement.dark)
  // An explicit choice ignores the OS preference and drops the listener.
  expect(scheme.listenerCount()).toBe(0)

  await light.click()
  await expect.element(light).toHaveAttribute('aria-pressed', 'true')
  expect(root.getAttribute('data-theme')).toBe('light')
  expect(window.localStorage.getItem('sh.theme')).toBe('light')

  await system.click()
  await expect.element(system).toHaveAttribute('aria-pressed', 'true')
  expect(root.getAttribute('data-theme')).toBe('light')
  expect(root.getAttribute('data-theme-choice')).toBe('system')
  expect(window.localStorage.getItem('sh.theme')).toBe('system')
})

test('follows the operating system while the choice is system', async () => {
  const scheme = fakeDarkSchemeQuery(true)
  const screen = await renderToggle()

  await expect.poll(() => root.getAttribute('data-theme')).toBe('dark')

  scheme.setDark(false)
  await expect.poll(() => root.getAttribute('data-theme')).toBe('light')

  await screen.getByRole('button', { name: copy.theme.system }).click()
  await expect.element(screen.getByRole('status')).toHaveTextContent(copy.theme.announcement.systemLight)

  scheme.setDark(true)
  await expect.poll(() => root.getAttribute('data-theme')).toBe('dark')
  await expect.element(screen.getByRole('status')).toHaveTextContent(copy.theme.announcement.systemDark)
})

test('starts from the attributes set by the boot script', async () => {
  fakeDarkSchemeQuery(false)
  root.setAttribute('data-theme', 'dark')
  root.setAttribute('data-theme-choice', 'dark')
  root.setAttribute('data-density', 'compact')

  const screen = await renderToggle()

  await expect
    .element(screen.getByRole('button', { name: copy.theme.dark }))
    .toHaveAttribute('aria-pressed', 'true')
  expect(root.getAttribute('data-theme')).toBe('dark')
  expect(root.getAttribute('data-density')).toBe('compact')
})

test('keeps working when storage is blocked', async () => {
  fakeDarkSchemeQuery(false)
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new DOMException('blocked', 'SecurityError')
  })
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
    throw new DOMException('blocked', 'SecurityError')
  })

  const screen = await renderToggle()
  await screen.getByRole('button', { name: copy.theme.dark }).click()

  expect(root.getAttribute('data-theme')).toBe('dark')
  vi.restoreAllMocks()
})
