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

type Screen = Awaited<ReturnType<typeof renderApp>>['screen']

async function openLibrary(permissions = MANAGER, search = '') {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  try {
    localStorage.removeItem('sh.media.view')
  } catch {
    // The grid is the default either way.
  }
  const app = await renderApp(`/acme/settings/media${search}`)
  await expect.element(app.screen.getByRole('list', { name: 'Media items' })).toBeVisible()
  return app
}

const grid = (screen: Screen) => screen.getByRole('list', { name: 'Media items' })
const card = (screen: Screen, name: string) => grid(screen).getByRole('listitem').filter({ hasText: name })
const shown = (screen: Screen, name: string) => grid(screen).getByRole('button', { name: `Preview ${name}` })

/** Opens a card's actions menu and chooses one of its items. */
async function cardAction(screen: Screen, name: string, action: string) {
  await card(screen, name)
    .getByRole('button', { name: `Actions for ${name}` })
    .click()
  await screen.getByRole('menuitem', { name: action }).click()
}

const pngFile = (name = 'diagram.png') => new File([new Uint8Array(2048)], name, { type: 'image/png' })
/** A real 1×1 PNG, for tests that look at the picture. */
const PIXEL_PNG_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
const pixelFile = (name: string) =>
  new File([Uint8Array.from(atob(PIXEL_PNG_BASE64), (char) => char.charCodeAt(0))], name, {
    type: 'image/png',
  })

test('the library is a grid of cards with search in the URL', async () => {
  const { screen, currentLocation } = await openLibrary()
  await expect.element(shown(screen, 'screenshot-27.png')).toBeVisible()
  await expect.element(card(screen, 'screenshot-27.png')).toMatchTextContent(/PNG image · 54 KB/)
  await screen.getByRole('searchbox', { name: 'Search Media items' }).fill('report-2')
  await expect.element(shown(screen, 'report-2.pdf')).toBeVisible()
  await expect.element(shown(screen, 'screenshot-27.png')).not.toBeInTheDocument()
  await expect.poll(() => currentLocation().search).toMatchObject({ search: 'report-2' })
})

test('the type filter and the sort are part of the URL', async () => {
  const { screen, currentLocation } = await openLibrary()
  await screen.getByRole('combobox', { name: 'Type' }).click()
  await screen.getByRole('option', { name: 'Documents' }).click()
  await expect.element(shown(screen, 'report-26.pdf')).toBeVisible()
  await expect.element(shown(screen, 'screenshot-27.png')).not.toBeInTheDocument()
  await expect.poll(() => currentLocation().search).toMatchObject({ type: 'document' })
  await screen.getByRole('combobox', { name: 'Sort' }).click()
  await screen.getByRole('option', { name: 'Name A–Z' }).click()
  await expect.poll(() => currentLocation().search).toMatchObject({ sort: 'name' })
  await expect.element(grid(screen).getByRole('listitem').first()).toMatchTextContent(/report-10\.pdf/)
})

test('the list view is the table, and the choice is remembered', async () => {
  const { screen } = await openLibrary()
  await screen.getByRole('button', { name: 'List' }).click()
  const table = screen.getByRole('table', { name: 'Media items' })
  await expect.element(table.getByRole('button', { name: 'screenshot-27.png' })).toBeVisible()
  await expect.element(screen.getByRole('button', { name: 'List' })).toHaveAttribute('aria-pressed', 'true')
  expect(localStorage.getItem('sh.media.view')).toBe('list')
  await table.getByRole('button', { name: 'screenshot-27.png' }).click()
  await expect.element(screen.getByRole('dialog', { name: 'screenshot-27.png' })).toBeVisible()
})

test('a card opens the lightbox: details, open in a new tab, download, and the neighbours', async () => {
  const { screen } = await openLibrary(MANAGER, '?search=screenshot-2')
  await shown(screen, 'screenshot-27.png').click()
  const box = screen.getByRole('dialog', { name: 'screenshot-27.png' })
  await expect.element(box).toBeVisible()
  await expect.element(box.getByRole('img', { name: 'screenshot-27.png' })).toBeVisible()
  await expect
    .element(box.getByRole('link', { name: 'Download' }))
    .toHaveAttribute('href', expect.stringMatching(/\/v1\/media\/[0-9a-f-]+\/download$/))
  const open = box.getByRole('link', { name: 'Open in a new tab' })
  await expect.element(open).toHaveAttribute('href', expect.stringMatching(/\/open$/))
  await expect.element(open).toHaveAttribute('target', '_blank')

  await box.getByRole('button', { name: 'Show details' }).click()
  const details = box.getByRole('complementary', { name: 'Details' })
  await expect.element(details).toMatchTextContent(/Dimensions800 × 600/)

  await box.getByRole('button', { name: 'Zoom in' }).click()
  await expect.element(box.getByRole('button', { name: 'Fit to screen (now 150%)' })).toBeVisible()

  await expect.element(box.getByRole('list', { name: 'Files' })).toBeVisible()
  await userEvent.keyboard('{ArrowRight}')
  const next = screen.getByRole('dialog', { name: 'screenshot-25.png' })
  await expect.element(next).toBeVisible()
  await expect
    .element(next.getByRole('button', { name: 'Show screenshot-25.png' }))
    .toHaveAttribute('aria-current', 'true')
  await userEvent.keyboard('{Escape}')
  await expect.element(screen.getByRole('dialog')).not.toBeInTheDocument()
})

test('folders are a tree: arrows move and open, Enter filters the grid', async () => {
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
  await expect.element(shown(screen, 'screenshot-1.png')).toBeVisible()
  expect(shown(screen, 'screenshot-27.png').query()).toBeNull()
  await userEvent.keyboard('{ArrowLeft}')
  await expect.element(brand).toHaveFocus()
  await userEvent.keyboard('{End}')
  await expect.element(tree.getByRole('treeitem', { name: 'Tickets' })).toHaveFocus()
  await userEvent.keyboard('{Home}')
  await expect.element(all).toHaveFocus()
})

test('trashing asks first, and Cancel keeps the Media item', async () => {
  const { screen } = await openLibrary()
  await cardAction(screen, 'screenshot-27.png', 'Move to trash')
  const dialog = screen.getByRole('alertdialog', { name: 'Move to the trash?' })
  await expect.element(dialog.getByRole('button', { name: 'Cancel' })).toHaveFocus()
  await dialog.getByRole('button', { name: 'Cancel' }).click()
  expect(db.media.find((item) => item.name === 'screenshot-27.png')?.state).toBe('ready')
  await cardAction(screen, 'screenshot-27.png', 'Move to trash')
  await screen.getByRole('alertdialog').getByRole('button', { name: 'Move to trash' }).click()
  await expect.element(screen.getByText('Media item moved to the trash.')).toBeVisible()
  expect(db.media.find((item) => item.name === 'screenshot-27.png')?.state).toBe('trashed')
})

test('a refused trash (in use) is explained inside the confirmation', async () => {
  const { screen } = await openLibrary(MANAGER, '?search=screenshot-1.png')
  await cardAction(screen, 'screenshot-1.png', 'Move to trash')
  const dialog = screen.getByRole('alertdialog')
  await dialog.getByRole('button', { name: 'Move to trash' }).click()
  await expect.element(dialog.getByRole('alert')).toMatchTextContent(/attached to a ticket/)
  await expect.element(dialog).toBeVisible()
})

test('the trash restores directly and purges after a confirmation', async () => {
  const { screen } = await openLibrary()
  await screen.getByRole('combobox', { name: 'Show' }).click()
  await screen.getByRole('option', { name: 'Trash' }).click()
  await expect.element(card(screen, 'screenshot-29.png')).toMatchTextContent(/In trash/)
  await cardAction(screen, 'report-30.pdf', 'Restore')
  await expect.element(screen.getByText('Media item restored.')).toBeVisible()
  await cardAction(screen, 'screenshot-29.png', 'Purge')
  const dialog = screen.getByRole('alertdialog', { name: 'Purge this Media item?' })
  expect(db.media.some((item) => item.name === 'screenshot-29.png')).toBe(true)
  await dialog.getByRole('button', { name: 'Purge' }).click()
  await expect.element(screen.getByText('Media item purged.')).toBeVisible()
  expect(db.media.some((item) => item.name === 'screenshot-29.png')).toBe(false)
})

test('selected cards move to a folder together', async () => {
  const { screen } = await openLibrary()
  await card(screen, 'screenshot-27.png').getByRole('checkbox', { name: 'Select screenshot-27.png' }).click()
  await card(screen, 'report-26.pdf').getByRole('checkbox', { name: 'Select report-26.pdf' }).click()
  const bar = screen.getByRole('region', { name: 'Actions for the selected files' })
  await expect.element(bar).toMatchTextContent(/2 selected/)
  await bar.getByRole('button', { name: 'Move to folder' }).click()
  await screen.getByRole('menuitem', { name: 'Logos' }).click()
  await expect.element(screen.getByText('2 Media items moved to Logos.')).toBeVisible()
  const logos = db.mediaFolders.find((folder) => folder.name === 'Logos')?.id
  expect(
    db.media
      .filter((item) => ['screenshot-27.png', 'report-26.pdf'].includes(item.name))
      .map((item) => item.folder_id),
  ).toEqual([logos, logos])
  await expect
    .element(screen.getByRole('region', { name: 'Actions for the selected files' }))
    .not.toBeInTheDocument()
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

test('system folders cannot be renamed or deleted, and a viewer only previews and downloads', async () => {
  const { screen } = await openLibrary(['media.view'])
  await screen.getByRole('treeitem', { name: 'Tickets' }).click()
  expect(screen.getByRole('button', { name: 'Delete folder' }).query()).toBeNull()
  expect(screen.getByRole('button', { name: 'New folder' }).query()).toBeNull()
  expect(screen.getByLabelText('Add files').query()).toBeNull()
  expect(screen.getByRole('button', { name: 'Upload files' }).query()).toBeNull()
  await screen.getByRole('treeitem', { name: 'All folders' }).click()
  expect(card(screen, 'screenshot-27.png').getByRole('checkbox').query()).toBeNull()
  await card(screen, 'screenshot-27.png')
    .getByRole('button', { name: 'Actions for screenshot-27.png' })
    .click()
  await expect.element(screen.getByRole('menuitem', { name: 'Download' })).toBeVisible()
  expect(screen.getByRole('menuitem', { name: 'Move to trash' }).query()).toBeNull()
  expect(screen.getByRole('menuitem', { name: 'Edit' }).query()).toBeNull()
})

test('editing a Media item maps a 422 to its name field and saves tags', async () => {
  const { screen } = await openLibrary()
  await cardAction(screen, 'screenshot-27.png', 'Edit')
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
  await expect.element(shown(screen, 'login-error.png')).toBeVisible()
})

test('an upload shows up in the grid; a rejected type never starts', async () => {
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
  await expect.element(shown(screen, 'diagram.png')).toBeVisible()
  expect(intents).toBe(1)
})

test('files dropped on the library upload into the open folder', async () => {
  const { screen } = await openLibrary()
  await screen.getByRole('treeitem', { name: 'Tickets' }).click()
  const data = new DataTransfer()
  data.items.add(pngFile('dropped.png'))
  const target = screen.getByRole('region', { name: 'Library contents' }).element()
  target.dispatchEvent(new DragEvent('dragenter', { dataTransfer: data, bubbles: true, cancelable: true }))
  await expect.element(screen.getByText('Drop files to upload them to Tickets')).toBeVisible()
  target.dispatchEvent(new DragEvent('drop', { dataTransfer: data, bubbles: true, cancelable: true }))
  await expect.element(screen.getByRole('list', { name: 'Files to attach' }).getByText('Ready')).toBeVisible()
  // Toasts outlive a test (sonner keeps them in a module store), so wait for the move itself.
  const tickets = db.mediaFolders.find((folder) => folder.name === 'Tickets')?.id
  await expect.poll(() => db.media.find((item) => item.name === 'dropped.png')?.folder_id).toBe(tickets)
})

test('an uploaded file previews from the device before and after it is stored', async () => {
  const { screen } = await openLibrary()
  await userEvent.upload(screen.getByLabelText('Add files'), pixelFile('diagram.png'))
  const uploads = screen.getByRole('list', { name: 'Files to attach' })
  await uploads.getByRole('button', { name: 'Preview diagram.png' }).click()
  const box = screen.getByRole('dialog', { name: 'diagram.png' })
  await expect.element(box.getByRole('img', { name: 'diagram.png' })).toBeVisible()
  await expect
    .element(box.getByRole('link', { name: 'Download' }))
    .toHaveAttribute('href', expect.stringMatching(/^blob:/))
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
