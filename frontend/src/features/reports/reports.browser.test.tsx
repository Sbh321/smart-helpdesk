import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { render } from 'vitest-browser-react'
import { ChartCard } from '@/components/shared/chart-card'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { reportRequests } from '@/test/msw/reports'
import { renderApp } from '@/test/render-app'
import { ChartTable } from './components/chart-table'
import { SeriesChart } from './components/series-chart'

/**
 * Roadmap M3-01 and M3-20: the parameter bar round-trips through the URL into the run request,
 * drill-down lands on the records, the dashboard's tiles link into the reports, and a chart's table
 * alternative carries the same numbers.
 */
const worker = setupMswWorker()

const PERMISSIONS = ['reports.view', 'tickets.view', 'contacts.view', 'agents.view']

function signIn(permissions = PERMISSIONS) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
}

function lastRun(report: string) {
  return reportRequests.run.filter((request) => request.report === report).at(-1)?.body
}

test('the parameter bar reads the URL, sends it to the run, and writes changes back', async () => {
  signIn()
  const app = await renderApp(
    '/acme/reports/rpt-t06?period=last_7d&group=team&compare=previous&priority=P1,P2',
  )
  const { screen } = app
  await expect
    .element(screen.getByRole('heading', { level: 1, name: 'Response and resolution times' }))
    .toBeVisible()
  await expect
    .poll(() => lastRun('rpt-t06'))
    .toEqual({
      period: 'last_7d',
      group: 'team',
      filter: { priority: 'P1,P2' },
      compare: true,
    })

  const bar = screen.getByRole('region', { name: copy.reports.parameters })
  await expect
    .element(bar.getByRole('combobox', { name: copy.reports.period }))
    .toMatchTextContent(/Last 7 days/)
  await expect.element(bar.getByRole('combobox', { name: copy.reports.groupBy })).toMatchTextContent(/Team/)
  await expect.element(bar.getByRole('switch', { name: copy.reports.compare })).toBeChecked()

  await bar.getByRole('combobox', { name: copy.reports.period }).click()
  await screen.getByRole('option', { name: 'Last 90 days' }).click()
  await expect.poll(() => app.currentLocation().search).toMatchObject({ period: 'last_90d', group: 'team' })
  await expect
    .poll(() => lastRun('rpt-t06'))
    .toEqual({
      period: 'last_90d',
      group: 'team',
      filter: { priority: 'P1,P2' },
      compare: true,
    })

  await bar.getByRole('switch', { name: copy.reports.compare }).click()
  await expect.poll(() => app.currentLocation().search).not.toHaveProperty('compare')
  await expect.poll(() => lastRun('rpt-t06')).not.toHaveProperty('compare')

  // The totals compare with the previous period only while the comparison is on.
  await expect.element(screen.getByRole('heading', { name: copy.reports.totals })).toBeVisible()
})

test('a custom range in the URL replaces the period in the run request', async () => {
  signIn()
  const { screen } = await renderApp('/acme/reports/rpt-t01?from=2026-09-01&to=2026-09-10')
  await expect.element(screen.getByRole('heading', { level: 1, name: 'Ticket volume' })).toBeVisible()
  await expect.poll(() => lastRun('rpt-t01')).toEqual({ from: '2026-09-01', to: '2026-09-10' })
  await expect
    .element(screen.getByRole('combobox', { name: copy.reports.period }))
    .toMatchTextContent(/Custom range/)
})

test('a number in the data table drills down to the records that measure counts', async () => {
  signIn()
  const app = await renderApp('/acme/reports/rpt-t01?group=priority')
  const { screen } = app
  const table = screen.getByRole('table', { name: fill(copy.reports.tableLabel, { title: 'Ticket volume' }) })
  await expect.element(table.getByText('P1 Critical')).toBeVisible()

  const drill = table.getByRole('button', { name: /: P1 Critical, Resolved$/ })
  await drill.click()

  const dialog = screen.getByRole('dialog', {
    name: fill(copy.reports.records.measureTitle, { label: 'P1 Critical', measure: 'Resolved' }),
  })
  await expect.element(dialog).toBeVisible()
  await expect
    .poll(() => app.currentLocation().search)
    .toMatchObject({ group: 'priority', drill: 'P1', drill_measure: 'resolved' })
  const query = reportRequests.records.at(-1)
  expect(query?.get('key')).toBe('P1')
  expect(query?.get('measure')).toBe('resolved')
  expect(query?.get('group')).toBe('priority')
  expect(query?.get('period')).toBe('last_30d')

  const records = dialog.getByRole('list', { name: copy.reports.records.label })
  const first = records.getByRole('link').first()
  await expect.element(first).toBeVisible()
  await expect
    .element(first)
    .toHaveAttribute('href', expect.stringMatching(/^\/acme\/tickets\/[0-9a-f-]{36}$/))

  await dialog.getByRole('button', { name: 'Close' }).first().click()
  await expect.poll(() => app.currentLocation().search).not.toHaveProperty('drill')
  expect(app.currentLocation().search).not.toHaveProperty('drill_measure')
})

test('the catalogue groups the reports and the navigation offers it with reports.view', async () => {
  signIn()
  const { screen } = await renderApp('/acme/reports')
  const catalogue = screen.getByRole('navigation', { name: copy.reports.catalogueLabel })
  await expect.element(catalogue.getByRole('heading', { name: copy.reports.groups.tickets })).toBeVisible()
  await expect.element(catalogue.getByRole('heading', { name: copy.reports.groups.sla })).toBeVisible()
  await expect.element(catalogue.getByRole('link', { name: /Workload heatmap/ })).toBeVisible()
  await expect
    .element(
      screen
        .getByRole('navigation', { name: copy.nav.primary })
        .getByRole('link', { name: copy.nav.reports }),
    )
    .toBeVisible()
})

test('without reports.view there is no Reports entry and the dashboard asks for access', async () => {
  signIn(['tickets.view'])
  const { screen } = await renderApp('/acme')
  await expect.element(screen.getByRole('heading', { name: copy.dashboard.noAccessTitle })).toBeVisible()
  await expect
    .element(
      screen
        .getByRole('navigation', { name: copy.nav.primary })
        .getByRole('link', { name: copy.nav.reports }),
    )
    .not.toBeInTheDocument()
})

test('the dashboard shows KPI tiles with their change and links each one to its report', async () => {
  signIn()
  const dashboardRequests: string[] = []
  worker.events.on('request:start', ({ request }) => {
    const url = new URL(request.url)
    if (url.pathname === '/v1/dashboard') dashboardRequests.push(url.searchParams.get('period') ?? '')
  })
  const app = await renderApp('/acme?period=last_7d')
  const { screen } = app
  const kpis = screen.getByRole('region', { name: copy.dashboard.kpis })
  const created = kpis.getByRole('region', { name: 'Tickets created' })
  await expect.element(created).toBeVisible()
  await expect.element(created.getByText(/^Up 25(\.0)?% from /)).toBeVisible()
  expect(dashboardRequests).toContain('last_7d')

  // Six charts, each with its table alternative in the page.
  const charts = screen.getByRole('region', { name: copy.dashboard.charts })
  await expect.element(charts.getByRole('heading', { name: 'Created and resolved per day' })).toBeVisible()
  expect(charts.getByRole('table').all()).toHaveLength(6)

  await created
    .getByRole('link', { name: fill(copy.dashboard.openReportFor, { title: 'Tickets created' }) })
    .click()
  await expect.poll(() => app.currentPath()).toBe('/acme/reports/rpt-t01')
  expect(app.currentLocation().search).toMatchObject({ period: 'last_7d' })
  await expect.element(screen.getByRole('heading', { level: 1, name: 'Ticket volume' })).toBeVisible()
  worker.events.removeAllListeners()
})

test('the dashboard period selector is stored in the URL', async () => {
  signIn()
  const app = await renderApp('/acme')
  const { screen } = app
  await expect.element(screen.getByRole('region', { name: 'Tickets created' })).toBeVisible()
  await screen.getByRole('combobox', { name: copy.dashboard.periodLabel }).click()
  await screen.getByRole('option', { name: 'This month' }).click()
  await expect.poll(() => app.currentLocation().search).toMatchObject({ period: 'this_month' })
})

test("a chart's table alternative holds the same numbers and can be shown", async () => {
  const measures = [
    { key: 'created', label: 'Created', unit: 'count' },
    { key: 'resolved', label: 'Resolved', unit: 'count' },
  ]
  const rows = [
    { key: '2026-09-20', label: '2026-09-20', values: { created: 12, resolved: 9 } },
    { key: '2026-09-21', label: '2026-09-21', values: { created: 1234, resolved: null } },
  ]
  const screen = await render(
    <ChartCard
      title="Created and resolved"
      showTableLabel={copy.reports.showTable}
      hideTableLabel={copy.reports.hideTable}
      table={
        <ChartTable caption="Created and resolved" dimensionLabel="Day" rows={rows} measures={measures} />
      }
    >
      <SeriesChart kind="line" rows={rows} measures={measures} label="Created and resolved" />
    </ChartCard>,
  )

  // Present for assistive technology before anything is opened, visually hidden.
  const table = screen.getByRole('table', { name: 'Created and resolved' })
  await expect.element(table).toBeInTheDocument()
  await expect.element(table.getByRole('columnheader', { name: 'Resolved' })).toBeInTheDocument()
  await expect.element(table.getByRole('row', { name: '2026-09-21 1,234 —' })).toBeInTheDocument()
  const wrapper = table.element().closest('[data-slot="chart-table"]')
  expect(wrapper?.classList.contains('sr-only')).toBe(true)

  const toggle = screen.getByRole('button', { name: copy.reports.showTable })
  await expect.element(toggle).toHaveAttribute('aria-expanded', 'false')
  await toggle.click()
  await expect
    .element(screen.getByRole('button', { name: copy.reports.hideTable }))
    .toHaveAttribute('aria-expanded', 'true')
  expect(wrapper?.classList.contains('sr-only')).toBe(false)
  await expect.element(table).toBeVisible()

  // The chart itself is drawn (an SVG surface from Recharts) next to the table.
  expect(screen.container.querySelector('svg.recharts-surface')).not.toBeNull()
})
