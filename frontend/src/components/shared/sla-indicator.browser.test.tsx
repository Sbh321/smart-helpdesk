import axe from 'axe-core'
import { expect, test } from 'vitest'
import { render } from 'vitest-browser-react'
import { SlaIndicator, type SlaState } from './sla-indicator'

const now = new Date('2026-09-18T09:00:00Z')
const soon = '2026-09-18T09:20:00Z'
const later = '2026-09-18T12:00:00Z'
const past = '2026-09-18T07:00:00Z'

const CASES: { state: SlaState; dueAt: string; reads: string }[] = [
  { state: 'running', dueAt: later, reads: 'On track · 3 hours left' },
  { state: 'warning', dueAt: soon, reads: 'Due soon · 20 minutes left' },
  { state: 'breached', dueAt: past, reads: 'Breached · 2 hours overdue' },
  { state: 'paused', dueAt: later, reads: 'Paused' },
  { state: 'met', dueAt: later, reads: 'Met' },
]

test.each(CASES)('$state reads "$reads" with its own icon', async ({ state, dueAt, reads }) => {
  const screen = await render(<SlaIndicator state={state} dueAt={dueAt} timeZone="UTC" now={now} />)
  await expect.element(screen.getByText(reads, { exact: true })).toBeVisible()
})

test('the five states differ by icon shape and word, not colour alone (greyscale)', async () => {
  const screen = await render(
    <div>
      {CASES.map(({ state, dueAt }) => (
        <SlaIndicator key={state} state={state} dueAt={dueAt} timeZone="UTC" now={now} />
      ))}
    </div>,
  )
  const icons = [...screen.container.querySelectorAll('svg')].map((svg) =>
    [...svg.classList].find((name) => name.startsWith('lucide-') && name !== 'lucide-icon'),
  )
  expect(icons).toHaveLength(5)
  expect(new Set(icons).size).toBe(5)
})

test('a paused timer shows no countdown, even when its due time is near', async () => {
  const screen = await render(<SlaIndicator state="paused" dueAt={soon} timeZone="UTC" now={now} />)
  expect(screen.container.textContent).toBe('Paused')
})

test('the compact form shows the time and keeps the state for screen readers; kind labels the timer', async () => {
  const screen = await render(
    <div>
      <SlaIndicator compact state="warning" dueAt={soon} timeZone="UTC" now={now} />
      <SlaIndicator kind="first_response" state="running" dueAt={later} timeZone="UTC" now={now} />
    </div>,
  )
  await expect.element(screen.getByText('20 minutes left', { exact: true })).toBeVisible()
  await expect.element(screen.getByText('Due soon', { exact: true })).toHaveClass('sr-only')
  await expect.element(screen.getByText('Response', { exact: true })).toBeVisible()
  expect(screen.container.querySelector('[title]')?.getAttribute('title')).toBe('Due 18 Sep 2026, 09:20')
  const results = await axe.run(screen.container)
  expect(results.violations).toEqual([])
})

test('no timer or a cancelled one reads as a dash', async () => {
  const screen = await render(<SlaIndicator state="cancelled" dueAt={later} timeZone="UTC" now={now} />)
  expect(screen.container.textContent).toBe('—')
})
