import { delay, HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { TEST_STORAGE_ORIGIN } from '@/test/msw/media'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const MANAGER = ['media.view', 'media.manage', 'media.upload']

async function openLibrary(permissions = MANAGER, search = '') {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp(`/acme/settings/media${search}`)
  await expect.element(app.screen.getByRole('table', { name: 'Media items' })).toBeVisible()
  return app
}

const pngFile = (name = 'diagram.png') => new File([new Uint8Array(2048)], name, { type: 'image/png' })

test('the library is a sortable table with search in the URL', async () => {
  const { screen, currentLocation } = await openLibrary()
  await expect.element(screen.getByRole('link', { name: 'screenshot-27.png' })).toBeVisible()
  await screen.getByRole('searchbox', { name: 'Search Media items' }).fill('report-2')
  await expect.element(screen.getByRole('link', { name: 'report-2.pdf' })).toBeVisible()
  await expect.element(screen.getByRole('link', { name: 'screenshot-27.png' })).not.toBeInTheDocument()
  await expect.poll(() => currentLocation().search).toMatchObject({ search: 'report-2' })
})

test('folders are a tree: arrows move and open, Enter filters the table', async () => {
  const { screen, currentLocation } = await openLibrary()
  const tree = screen.getByRole('tree', { name: 'Folders' })
  const all = tree.getByRole('treeitem', { name: 'All folders' })
  await expect.element(all).toHaveAttribute('aria-selected', 'true')
  ;(all.element() as HTMLElement).focus()
  await userEvent.keyboard('{ArrowDown}')
  const brand = tree.getByRole('treeitem', { name: 'Brand' })
  await expect.element(brand).toHaveFocus()
  await expect.element(brand).toHaveAttribute('aria-expanded', 'false')
  expect(tree.getByRole('treeitem', { name: 'Logos' }).query()).toBeNull()
  await userEvent.keyboard('{ArrowRight}')
  await expect.element(brand).toHaveAttribute('aria-expanded', 'true')
  await userEvent.keyboard('{ArrowRight}')
  const logos = tree.getByRole('treeitem', { name: 'Logos' })
  await expect.element(logos).toHaveFocus()
  await expect.element(logos).toHaveAttribute('aria-level', '2')
  await userEvent.keyboard('{Enter}')
  await expect.element(logos).toHaveAttribute('aria-selected', 'true')
  await expect.poll(() => currentLocation().searchStr).toContain('folder_id')
  await expect.element(screen.getByRole('link', { name: 'screenshot-1.png' })).toBeVisible()
  expect(screen.getByRole('link', { name: 'screenshot-27.png' }).query()).toBeNull()
  await userEvent.keyboard('{ArrowLeft}')
  await expect.element(brand).toHaveFocus()
  await userEvent.keyboard('{End}')
  await expect.element(tree.getByRole('treeitem', { name: 'Tickets' })).toHaveFocus()
  await userEvent.keyboard('{Home}')
  await expect.element(all).toHaveFocus()
})

test('trashing asks first, and Cancel keeps the Media item', async () => {
  const { screen } = await openLibrary()
  await screen.getByRole('button', { name: 'Move screenshot-27.png to the trash' }).click()
  const dialog = screen.getByRole('alertdialog', { name: 'Move to the trash?' })
  await expect.element(dialog.getByRole('button', { name: 'Cancel' })).toHaveFocus()
  await dialog.getByRole('button', { name: 'Cancel' }).click()
  expect(db.media.find((item) => item.name === 'screenshot-27.png')?.state).toBe('ready')
  await screen.getByRole('button', { name: 'Move screenshot-27.png to the trash' }).click()
  await screen.getByRole('alertdialog').getByRole('button', { name: 'Move to trash' }).click()
  await expect.element(screen.getByText('Media item moved to the trash.')).toBeVisible()
  expect(db.media.find((item) => item.name === 'screenshot-27.png')?.state).toBe('trashed')
})

test('a refused trash (in use) is explained inside the confirmation', async () => {
  const { screen } = await openLibrary(MANAGER, '?search=screenshot-1.png')
  await screen.getByRole('button', { name: 'Move screenshot-1.png to the trash' }).click()
  const dialog = screen.getByRole('alertdialog')
  await dialog.getByRole('button', { name: 'Move to trash' }).click()
  await expect.element(dialog.getByRole('alert')).toMatchTextContent(/attached to a ticket/)
  await expect.element(dialog).toBeVisible()
})

test('the trash restores directly and purges after a confirmation', async () => {
  const { screen } = await openLibrary()
  await screen.getByRole('combobox', { name: 'Show' }).click()
  await screen.getByRole('option', { name: 'Trash' }).click()
  await expect.element(screen.getByRole('link', { name: 'screenshot-29.png' })).toBeVisible()
  await screen.getByRole('button', { name: 'Restore report-30.pdf' }).click()
  await expect.element(screen.getByText('Media item restored.')).toBeVisible()
  await screen.getByRole('button', { name: 'Purge screenshot-29.png' }).click()
  const dialog = screen.getByRole('alertdialog', { name: 'Purge this Media item?' })
  expect(db.media.some((item) => item.name === 'screenshot-29.png')).toBe(true)
  await dialog.getByRole('button', { name: 'Purge' }).click()
  await expect.element(screen.getByText('Media item purged.')).toBeVisible()
  expect(db.media.some((item) => item.name === 'screenshot-29.png')).toBe(false)
})

test('a folder is created with field validation, renamed and deleted after a confirmation', async () => {
  const { screen } = await openLibrary()
  await screen.getByRole('button', { name: 'New folder' }).click()
  const dialog = screen.getByRole('dialog', { name: 'New folder' })
  await dialog.getByRole('button', { name: 'New folder' }).click()
  await expect.element(dialog.getByText('Enter a name.')).toBeVisible()
  await dialog.getByRole('textbox', { name: 'Folder name' }).fill('Brand')
  await dialog.getByRole('button', { name: 'New folder' }).click()
  await expect
    .element(dialog.getByRole('textbox', { name: 'Folder name' }))
    .toHaveAccessibleDescription('The name has already been taken.')
  await dialog.getByRole('textbox', { name: 'Folder name' }).fill('Invoices')
  await dialog.getByRole('button', { name: 'New folder' }).click()
  await expect.element(screen.getByText('Folder created.')).toBeVisible()

  const tree = screen.getByRole('tree', { name: 'Folders' })
  await tree.getByRole('treeitem', { name: 'Invoices' }).click()
  await screen.getByRole('button', { name: 'Rename' }).click()
  const rename = screen.getByRole('dialog', { name: 'Rename folder' })
  await rename.getByRole('textbox', { name: 'Folder name' }).fill('Receipts')
  await rename.getByRole('button', { name: 'Save' }).click()
  await expect.element(tree.getByRole('treeitem', { name: 'Receipts' })).toBeVisible()

  await screen.getByRole('button', { name: 'Delete folder' }).click()
  const confirm = screen.getByRole('alertdialog', { name: 'Delete this folder?' })
  expect(db.mediaFolders.some((folder) => folder.name === 'Receipts')).toBe(true)
  await confirm.getByRole('button', { name: 'Delete folder' }).click()
  await expect.element(screen.getByText('Folder deleted.')).toBeVisible()
  expect(db.mediaFolders.some((folder) => folder.name === 'Receipts')).toBe(false)
})

test('system folders cannot be renamed or deleted, and a viewer gets no actions', async () => {
  const { screen } = await openLibrary(['media.view'])
  await screen.getByRole('treeitem', { name: 'Tickets' }).click()
  expect(screen.getByRole('button', { name: 'Delete folder' }).query()).toBeNull()
  expect(screen.getByRole('button', { name: 'New folder' }).query()).toBeNull()
  expect(screen.getByRole('button', { name: /to the trash/ }).query()).toBeNull()
  expect(screen.getByLabelText('Add files').query()).toBeNull()
})

test('editing a Media item maps a 422 to its name field and saves tags', async () => {
  const { screen } = await openLibrary()
  await screen.getByRole('button', { name: 'Edit screenshot-27.png' }).click()
  const dialog = screen.getByRole('dialog', { name: 'Edit Media item' })
  const name = dialog.getByRole('textbox', { name: 'Name' })
  await name.fill('')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect.element(name).toHaveAccessibleDescription('Enter a name.')
  worker.use(
    http.patch(
      apiUrl('/media/{media}'),
      () => problem(422, 'validation_failed', { errors: { name: ['The name may not contain a slash.'] } }),
      { once: true },
    ),
  )
  await name.fill('a/b.png')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect.element(name).toHaveAccessibleDescription('The name may not contain a slash.')
  await name.fill('login-error.png')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect.element(screen.getByText('Media library updated.')).toBeVisible()
  await expect.element(screen.getByRole('link', { name: 'login-error.png' })).toBeVisible()
})

test('an upload shows up in the table; a rejected type never starts', async () => {
  let intents = 0
  worker.events.on('request:start', ({ request }) => {
    if (request.url.endsWith('/media/intent')) intents += 1
  })
  const { screen } = await openLibrary()
  await userEvent.upload(screen.getByLabelText('Add files'), [pngFile(), new File(['x'], 'run.exe')])
  const uploads = screen.getByRole('list', { name: 'Files to attach' })
  await expect.element(uploads.getByText('Ready')).toBeVisible()
  await expect.element(uploads.getByText('This file type is not allowed.')).toBeVisible()
  expect(uploads.getByRole('button', { name: 'Retry run.exe' }).query()).toBeNull()
  await expect.element(screen.getByRole('link', { name: 'diagram.png' })).toBeVisible()
  expect(intents).toBe(1)
})

test('cancelling an upload aborts the PUT and never completes it', async () => {
  let completed = 0
  worker.use(
    http.put(`${TEST_STORAGE_ORIGIN}/upload/:media`, async () => {
      await delay('infinite')
      return new HttpResponse(null, { status: 200 })
    }),
    http.post(apiUrl('/media/{media}/complete'), () => {
      completed += 1
      return problem(500, 'server_error')
    }),
  )
  const { screen } = await openLibrary()
  await userEvent.upload(screen.getByLabelText('Add files'), pngFile())
  await expect.element(screen.getByRole('progressbar', { name: 'Uploading diagram.png' })).toBeVisible()
  await screen.getByRole('button', { name: 'Cancel upload of diagram.png' }).click()
  await expect.element(screen.getByRole('list', { name: 'Files to attach' })).not.toBeInTheDocument()
  await expect.element(screen.getByRole('status').filter({ hasText: 'cancelled' })).toBeInTheDocument()
  expect(completed).toBe(0)
})

test('retry after a failed complete reuses the stored upload instead of a new intent', async () => {
  let intents = 0
  let puts = 0
  worker.events.on('request:start', ({ request }) => {
    if (request.url.endsWith('/media/intent')) intents += 1
    if (request.method === 'PUT' && request.url.startsWith(TEST_STORAGE_ORIGIN)) puts += 1
  })
  worker.use(
    http.post(
      apiUrl('/media/{media}/complete'),
      () => problem(503, 'unavailable', { detail: 'Try again.' }),
      {
        once: true,
      },
    ),
  )
  const { screen } = await openLibrary()
  await userEvent.upload(screen.getByLabelText('Add files'), pngFile())
  await screen.getByRole('button', { name: 'Retry diagram.png' }).click()
  await expect.element(screen.getByRole('list', { name: 'Files to attach' }).getByText('Ready')).toBeVisible()
  expect({ intents, puts }).toEqual({ intents: 1, puts: 1 })
})

test('retry after a failed PUT asks for a fresh intent', async () => {
  let intents = 0
  worker.events.on('request:start', ({ request }) => {
    if (request.url.endsWith('/media/intent')) intents += 1
  })
  worker.use(
    http.put(`${TEST_STORAGE_ORIGIN}/upload/:media`, () => new HttpResponse(null, { status: 500 }), {
      once: true,
    }),
  )
  const { screen } = await openLibrary()
  await userEvent.upload(screen.getByLabelText('Add files'), pngFile())
  await expect.element(screen.getByText('File storage returned HTTP 500.')).toBeVisible()
  await screen.getByRole('button', { name: 'Retry diagram.png' }).click()
  await expect.element(screen.getByRole('list', { name: 'Files to attach' }).getByText('Ready')).toBeVisible()
  expect(intents).toBe(2)
})
