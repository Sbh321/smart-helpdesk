import { expect, test } from 'vitest'
import { render } from 'vitest-browser-react'
import { Sparkline } from './sparkline'

test('a missing point breaks the line instead of drawing zero', async () => {
  const screen = await render(<Sparkline values={[3, 5, null, 4, 6]} />)
  const path = screen.container.querySelector('path')?.getAttribute('d') ?? ''
  expect(path.match(/M/g)).toHaveLength(2)
  expect(path.match(/L/g)).toHaveLength(2)
  expect(screen.container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true')
})

test('fewer than two known points draw nothing', async () => {
  const screen = await render(<Sparkline values={[null, 4, null]} />)
  expect(screen.container.querySelector('svg')).toBeNull()
})
