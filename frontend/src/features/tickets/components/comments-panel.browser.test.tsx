import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const AGENT = ['tickets.view', 'tickets.update', 'comments.internal', 'media.view', 'media.upload']

async function openComments(permissions = AGENT) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const ticket = db.tickets[2]
  if (!ticket) throw new Error('Missing ticket fixture')
  const app = await renderApp(`/acme/tickets/${ticket.id}`)
  await app.screen.getByRole('tab', { name: 'Comments' }).click()
  const panel = app.screen.getByRole('region', { name: 'Ticket comments' })
  await expect.element(panel.getByText('No comments yet.')).toBeVisible()
  return { ...app, ticket, panel }
}

test('a public reply is sent as public and says who will see it', async () => {
  const { screen, panel, ticket } = await openComments()
  await expect.element(panel.getByRole('radio', { name: 'Public reply' })).toBeChecked()
  await expect.element(panel.getByText('This reply will be emailed to the Contact.')).toBeVisible()
  await expect.element(panel.getByRole('button', { name: 'Send reply to the Contact' })).toBeDisabled()
  await panel.getByRole('textbox', { name: 'Message' }).fill('We have **reset** your password.')
  await panel.getByRole('button', { name: 'Send reply to the Contact' }).click()
  await expect.element(screen.getByText('Comment added.')).toBeVisible()
  await expect.element(panel.getByText('reset', { exact: true })).toBeVisible()
  await expect.element(panel.getByRole('textbox', { name: 'Message' })).toHaveValue('')
  expect(db.comments[ticket.id]?.[0]).toMatchObject({ visibility: 'public' })
})

test('an internal note is marked as internal in the request and in the list', async () => {
  const { panel, ticket } = await openComments()
  await panel.getByRole('radio', { name: 'Internal note' }).click()
  await expect.element(panel.getByText('Only Workspace staff can see this note.')).toBeVisible()
  await panel.getByRole('textbox', { name: 'Message' }).fill('Customer called twice.')
  await panel.getByRole('button', { name: 'Save internal note' }).click()
  await expect
    .element(panel.getByRole('listitem').filter({ hasText: 'Customer called twice.' }))
    .toMatchTextContent(/Internal note/)
  expect(db.comments[ticket.id]?.[0]).toMatchObject({
    visibility: 'internal',
    body: 'Customer called twice.',
  })
})

test('without comments.internal only a public reply can be written', async () => {
  const { panel } = await openComments(['tickets.view', 'tickets.update'])
  // Without the permission there is nothing to choose, so M4-05 drops the switch entirely and the
  // button says who will read it.
  expect(panel.getByRole('radio', { name: 'Internal note' }).query()).toBeNull()
  expect(panel.getByRole('radio', { name: 'Public reply' }).query()).toBeNull()
  await expect.element(panel.getByRole('button', { name: 'Send reply to the Contact' })).toBeVisible()
})

test('a viewer reads comments but gets no composer', async () => {
  const { panel } = await openComments(['tickets.view'])
  expect(panel.getByRole('textbox', { name: 'Message' }).query()).toBeNull()
})

test('an uploaded file travels with the comment and is listed on it', async () => {
  let sent: { media_ids?: string[] } = {}
  worker.events.on('request:start', async ({ request }) => {
    if (request.method === 'POST' && request.url.endsWith('/comments')) sent = await request.clone().json()
  })
  const { panel } = await openComments()
  await panel.getByRole('textbox', { name: 'Message' }).fill('Screenshot attached.')
  await userEvent.upload(
    panel.getByLabelText('Add files'),
    new File([new Uint8Array(1024)], 'error.png', { type: 'image/png' }),
  )
  await expect.element(panel.getByRole('list', { name: 'Files to attach' }).getByText('Ready')).toBeVisible()
  await panel.getByRole('button', { name: 'Send reply to the Contact' }).click()
  await expect
    .element(panel.getByRole('listitem').filter({ hasText: 'Screenshot attached.' }))
    .toMatchTextContent(/error\.png/)
  expect(sent.media_ids).toHaveLength(1)
  await expect.element(panel.getByRole('list', { name: 'Files to attach' })).not.toBeInTheDocument()
})

test('files chosen from the library travel with the comment and open in the lightbox', async () => {
  let sent: { media_ids?: string[] } = {}
  worker.events.on('request:start', async ({ request }) => {
    if (request.method === 'POST' && request.url.endsWith('/comments')) sent = await request.clone().json()
  })
  const { screen, panel } = await openComments()
  await panel.getByRole('textbox', { name: 'Message' }).fill('See the earlier screenshot.')
  await panel.getByRole('button', { name: 'Choose from library' }).click()
  const picker = screen.getByRole('dialog', { name: 'Choose from the Media library' })
  await expect.element(picker.getByRole('button', { name: 'Use file' })).toBeDisabled()
  await picker.getByRole('button', { name: 'Select screenshot-27.png' }).click()
  await picker.getByRole('button', { name: 'Select report-26.pdf' }).click()
  await expect.element(picker.getByText('2 selected')).toBeVisible()
  await picker.getByRole('button', { name: 'Use 2 files' }).click()
  await expect.element(picker).not.toBeInTheDocument()

  const tiles = panel.getByRole('list', { name: 'Files to attach' })
  await expect
    .element(tiles.getByRole('listitem').filter({ hasText: 'screenshot-27.png' }))
    .toMatchTextContent(/From the library/)
  await panel.getByRole('button', { name: 'Choose from library' }).click()
  await expect
    .element(
      screen
        .getByRole('dialog', { name: 'Choose from the Media library' })
        .getByRole('button', { name: 'screenshot-27.png (already added)' }),
    )
    .toBeDisabled()
  await userEvent.keyboard('{Escape}')

  await panel.getByRole('button', { name: 'Send reply to the Contact' }).click()
  const comment = panel.getByRole('listitem').filter({ hasText: 'See the earlier screenshot.' })
  await expect.element(comment.getByRole('list', { name: 'Attached files' })).toBeVisible()
  expect(sent.media_ids).toHaveLength(2)
  await comment.getByRole('button', { name: 'Preview report-26.pdf' }).click()
  const box = screen.getByRole('dialog', { name: 'report-26.pdf' })
  await expect.element(box.getByTitle('report-26.pdf')).toBeInTheDocument()
  await expect.element(box.getByText(/ of 2: report-26\.pdf$/)).toBeInTheDocument()
})

test('a comment body is rendered as text: markup in it stays inert', async () => {
  const { panel } = await openComments()
  await panel
    .getByRole('textbox', { name: 'Message' })
    .fill('<img src=x onerror="window.__xss = 1"> [x](javascript:alert(1))')
  await panel.getByRole('button', { name: 'Send reply to the Contact' }).click()
  await expect.element(panel.getByRole('listitem').filter({ hasText: '<img src=x' })).toBeVisible()
  expect(panel.element().querySelector('ol img, ol a[href^="javascript"]')).toBeNull()
  expect((window as { __xss?: number }).__xss).toBeUndefined()
})

test('a failed comment keeps the draft and explains the failure', async () => {
  worker.use(
    http.post(apiUrl('/tickets/{ticket}/comments'), () =>
      problem(503, 'unavailable', { detail: 'Try again shortly.' }),
    ),
  )
  const { panel } = await openComments()
  await panel.getByRole('textbox', { name: 'Message' }).fill('Still there?')
  await panel.getByRole('button', { name: 'Send reply to the Contact' }).click()
  await expect.element(panel.getByRole('alert')).toMatchTextContent(/Try again shortly\./)
  await expect.element(panel.getByRole('textbox', { name: 'Message' })).toHaveValue('Still there?')
})
