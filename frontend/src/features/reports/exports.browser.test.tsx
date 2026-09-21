import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { EXPORT_MEDIA_ID, exportRequests } from '@/test/msw/exports'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/**
 * Roadmap M3-09: the report page and the ticket list request an export with their current parameters,
 * say it is queued, poll it until it is ready and offer the file; the bell shows "Export ready".
 */
const worker = setupMswWorker()

const EXPORTER = [
  'reports.view',
  'reports.export',
  'tickets.view',
  'contacts.view',
  'agents.view',
  'media.view',
]
const text = copy.exports

function signIn(permissions = EXPORTER) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
}

test('a report is exported with the parameters in the URL and offered for download when ready', async () => {
  signIn()
  const { screen } = await renderApp('/acme/reports/rpt-t01?period=last_7d&group=priority&priority=P1')
  await expect.element(screen.getByRole('heading', { level: 1, name: 'Ticket volume' })).toBeVisible()

  await screen.getByRole('button', { name: text.button.xlsx }).click()

  await expect.element(screen.getByText(text.queued)).toBeVisible()
  expect(exportRequests.at(-1)).toEqual({
    path: '/reports/rpt-t01/exports',
    body: {
      format: 'xlsx',
      parameters: { period: 'last_7d', group: 'priority', filter: { priority: 'P1' } },
    },
  })
  // While the job runs the buttons wait; the file then appears as a link to the Media download route.
  const link = screen.getByRole('link', {
    name: fill(text.download, { name: 'Ticket volume 2026-09-21 1445.xlsx', rows: 12 }),
  })
  await expect.element(link).toBeVisible()
  await expect.element(link).toHaveAttribute('href', `https://api.test/v1/media/${EXPORT_MEDIA_ID}/download`)
  await expect.element(screen.getByText(text.ready)).toBeVisible()
  await expect.element(screen.getByRole('button', { name: text.button.csv })).toBeEnabled()
})

test('the ticket list exports its current filters, search and sort', async () => {
  signIn()
  const { screen } = await renderApp('/acme/tickets?status=open,pending&search=printer&sort=-created_at')
  await screen.getByRole('button', { name: text.button.csv }).click()

  await expect
    .poll(() => exportRequests.at(-1))
    .toEqual({
      path: '/exports/tickets',
      body: { format: 'csv', sort: '-created_at', search: 'printer', filter: { status: 'open,pending' } },
    })
  await expect
    .element(
      screen.getByRole('link', {
        name: fill(text.download, { name: 'Tickets 2026-09-21 1445.csv', rows: 12 }),
      }),
    )
    .toBeVisible()
})

test('a refused export says why', async () => {
  signIn()
  worker.use(
    http.post(apiUrl('/exports/tickets'), () =>
      problem(422, 'validation_failed', {
        title: 'The given data was invalid',
        errors: { filter: ['The list has 60000 tickets; narrow the filters to at most 50000.'] },
      }),
    ),
  )
  const { screen } = await renderApp('/acme/tickets')
  await screen.getByRole('button', { name: text.button.csv }).click()
  await expect
    .element(screen.getByText('The list has 60000 tickets; narrow the filters to at most 50000.'))
    .toBeVisible()
  await expect.element(screen.getByRole('button', { name: text.button.csv })).toBeEnabled()
})

test('a failed export names the reason', async () => {
  signIn()
  worker.use(
    http.get(apiUrl('/exports/{export}'), ({ params }) =>
      HttpResponse.json({
        data: {
          id: String(params.export),
          report_key: 'rpt-t01',
          format: 'csv' as const,
          state: 'failed' as const,
          row_count: null,
          error: 'quota_exceeded',
          media_id: null,
          file_name: null,
          size_bytes: null,
          download_url: null,
          created_at: '2026-09-21T09:00:00Z',
          finished_at: '2026-09-21T09:00:02Z',
        },
      }),
    ),
  )
  const { screen } = await renderApp('/acme/reports/rpt-t01')
  await screen.getByRole('button', { name: text.button.csv }).click()
  await expect.element(screen.getByText(text.failed.quota_exceeded ?? '').first()).toBeVisible()
})

test('without reports.export there are no export buttons', async () => {
  signIn(['reports.view', 'tickets.view'])
  const { screen } = await renderApp('/acme/reports/rpt-t01')
  await expect.element(screen.getByRole('heading', { level: 1, name: 'Ticket volume' })).toBeVisible()
  await expect.element(screen.getByRole('button', { name: copy.reports.print })).toBeVisible()
  await expect.element(screen.getByRole('button', { name: text.button.csv })).not.toBeInTheDocument()
})

test('without reports.export the ticket list has no export buttons', async () => {
  signIn(['tickets.view'])
  const { screen } = await renderApp('/acme/tickets')
  await expect.element(screen.getByText('#1001')).toBeVisible()
  await expect.element(screen.getByRole('button', { name: text.button.xlsx })).not.toBeInTheDocument()
})

test('the bell shows a finished export with a link to the file', async () => {
  signIn()
  db.notifications.unshift({
    id: '01990000-0000-7000-8000-00000000f001',
    kind: 'export_ready',
    ticket_id: null,
    ticket_number: null,
    ticket_title: null,
    export_id: '01990000-0000-7000-8000-000000000001',
    media_id: EXPORT_MEDIA_ID,
    file_name: 'Ticket volume 2026-09-21 1445.csv',
    summary: 'Your export is ready',
    read_at: null,
    created_at: '2026-09-21T09:30:00Z',
  })
  const { screen } = await renderApp('/acme/notifications')
  await expect
    .element(
      screen.getByRole('link', {
        name: fill(copy.notifications.download, { name: 'Ticket volume 2026-09-21 1445.csv' }),
      }),
    )
    .toHaveAttribute('href', `https://api.test/v1/media/${EXPORT_MEDIA_ID}/download`)
})
