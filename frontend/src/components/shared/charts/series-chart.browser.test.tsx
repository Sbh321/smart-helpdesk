import { expect, test } from 'vitest'
import { render } from 'vitest-browser-react'
import { type ChartRow, SeriesChart, type SeriesKind } from './series-chart'

const measures = [
  { key: 'met', label: 'Met', unit: 'count' },
  { key: 'breached', label: 'Breached', unit: 'count' },
]
const rows: ChartRow[] = [
  { key: 'a', label: 'Billing', values: { met: 8, breached: 2 } },
  { key: 'b', label: 'Technical', values: { met: 5, breached: 5 } },
  { key: 'c', label: 'No team', values: { met: 3, breached: 1 } },
]
const days: ChartRow[] = [
  { key: '2026-08-24', label: '24 Aug', fullLabel: 'Mon 24 Aug 2026', values: { met: 4, breached: 1 } },
  { key: '2026-08-25', label: '25 Aug', fullLabel: 'Tue 25 Aug 2026', values: { met: 6, breached: 0 } },
  { key: '2026-08-26', label: '26 Aug', fullLabel: 'Wed 26 Aug 2026', values: { met: 2, breached: 3 } },
]

async function draw(kind: SeriesKind, data: readonly ChartRow[], time = false, shown = measures) {
  const screen = await render(
    <div style={{ width: 640 }}>
      <SeriesChart kind={kind} rows={data} measures={shown} label="Test chart" time={time} />
    </div>,
  )
  await expect.poll(() => screen.container.querySelector('.recharts-surface')).not.toBeNull()
  return screen
}

/** The drawn bars of one measure (series index), as boxes. */
function bars(container: HTMLElement, series: number): DOMRect[] {
  const group = container.querySelectorAll('.recharts-bar')[series]
  return [...(group?.querySelectorAll('.recharts-rectangle') ?? [])].map((shape) =>
    (shape as SVGGraphicsElement).getBoundingClientRect(),
  )
}

test('every kind has a legend naming its measures, one measure too (M4-09)', async () => {
  for (const kind of ['line', 'area', 'bar', 'stacked_bar', 'histogram'] as const) {
    const screen = await draw(kind, kind === 'line' || kind === 'area' ? days : rows, kind !== 'bar', [
      measures[0] as (typeof measures)[number],
    ])
    await expect.element(screen.getByText('Met', { exact: true }).first()).toBeVisible()
    expect(screen.container.querySelector('.recharts-legend-wrapper'), kind).not.toBeNull()
    await screen.unmount()
  }
})

test('bars across categories are horizontal: a ranking whose long names fit', async () => {
  const { container } = await draw('bar', rows)
  const first = bars(container, 0)
  expect(first).toHaveLength(3)
  // One left edge, different lengths.
  expect(new Set(first.map((box) => Math.round(box.left))).size).toBe(1)
  expect(first[0]?.width).toBeGreaterThan(first[2]?.width ?? 0)
})

test('bars over time stand upright in time order', async () => {
  const { container } = await draw('bar', days, true)
  const first = bars(container, 0)
  expect(new Set(first.map((box) => Math.round(box.bottom))).size).toBe(1)
  expect(first[0]?.left).toBeLessThan(first[1]?.left ?? 0)
})

test('a stacked bar puts each part after the previous one, not beside it', async () => {
  const { container } = await draw('stacked_bar', rows)
  const met = bars(container, 0)
  const breached = bars(container, 1)
  met.forEach((box, index) => {
    expect(Math.abs((breached[index]?.left ?? 0) - box.right)).toBeLessThan(1.5)
    expect(Math.round(breached[index]?.top ?? 0)).toBe(Math.round(box.top))
  })
})

test('a stacked bar over time stacks upward', async () => {
  const { container } = await draw('stacked_bar', days, true)
  const met = bars(container, 0)
  const breached = bars(container, 1).filter((box) => box.height > 0)
  expect(Math.abs((breached[0]?.bottom ?? 0) - (met[0]?.top ?? 0))).toBeLessThan(1.5)
})

test('grouped bars (several measures, not stacked) sit side by side', async () => {
  const { container } = await draw('bar', rows)
  const met = bars(container, 0)
  const breached = bars(container, 1)
  expect(Math.round(met[0]?.left ?? 0)).toBe(Math.round(breached[0]?.left ?? 0))
  expect(breached[0]?.top).toBeGreaterThan(met[0]?.top ?? 0)
})

test('a histogram stands upright and gapless', async () => {
  const { container } = await draw('histogram', rows, false, [measures[0] as (typeof measures)[number]])
  const boxes = bars(container, 0)
  expect(new Set(boxes.map((box) => Math.round(box.bottom))).size).toBe(1)
  expect((boxes[1]?.left ?? 0) - (boxes[0]?.right ?? 0)).toBeLessThan(3)
})

test('the axis shows the short date; the long date stays for the tooltip and never an ISO key', async () => {
  const { container } = await draw('line', days, true)
  const ticks = [...container.querySelectorAll('.recharts-cartesian-axis-tick-value')].map(
    (node) => node.textContent,
  )
  expect(ticks).toContain('24 Aug')
  expect(ticks.some((tick) => /\d{4}-\d{2}-\d{2}/.test(tick ?? ''))).toBe(false)
})

test('the legend follows the series order, the order of a stack, not the alphabet', async () => {
  const { container } = await draw('stacked_bar', rows, false, [
    { key: 'met', label: 'Met', unit: 'count' },
    { key: 'breached', label: 'Breached', unit: 'count' },
    { key: 'running', label: 'Still running', unit: 'count' },
  ])
  const legend = container.querySelector('.recharts-legend-wrapper')?.textContent ?? ''
  expect(legend.indexOf('Met')).toBeLessThan(legend.indexOf('Breached'))
  expect(legend.indexOf('Breached')).toBeLessThan(legend.indexOf('Still running'))
})
