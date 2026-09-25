import axe from 'axe-core'
import { FileArchiveIcon } from 'lucide-react'
import { useState } from 'react'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { render } from 'vitest-browser-react'
import { Lightbox, type LightboxItem } from './lightbox'

const PIXEL =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

const ITEMS: LightboxItem[] = [
  {
    id: 'a',
    name: 'photo.png',
    kind: 'image',
    summary: 'PNG image · 2 KB · 1 × 1',
    thumbUrl: PIXEL,
    previewUrl: PIXEL,
    sourceUrl: PIXEL,
    openUrl: 'https://files.test/photo.png',
    downloadUrl: 'https://files.test/photo.png?download',
    details: [{ label: 'Dimensions', value: '1 × 1' }],
  },
  {
    id: 'b',
    name: 'notes.txt',
    kind: 'text',
    summary: 'Text file · 1 KB',
    sourceUrl: 'about:blank',
    openUrl: 'about:blank',
    downloadUrl: 'https://files.test/notes.txt',
  },
  {
    id: 'c',
    name: 'logs.zip',
    kind: 'file',
    summary: 'ZIP archive · 4 MB',
    icon: FileArchiveIcon,
    downloadUrl: 'https://files.test/logs.zip',
  },
]

function Harness({ start = 0 }: { start?: number }) {
  const [index, setIndex] = useState<number | null>(start)
  return (
    <>
      <button type="button" onClick={() => setIndex(0)}>
        Open
      </button>
      <Lightbox items={ITEMS} index={index} onIndexChange={setIndex} onClose={() => setIndex(null)} />
    </>
  )
}

test('an image zooms with the buttons and the keys, rotates, and fits again', async () => {
  const screen = await render(<Harness />)
  const box = screen.getByRole('dialog', { name: 'photo.png' })
  await expect.element(box).toHaveAccessibleDescription('PNG image · 2 KB · 1 × 1')
  const image = box.getByRole('img', { name: 'photo.png' })
  await expect.element(image).toBeVisible()

  await box.getByRole('button', { name: 'Zoom in' }).click()
  await expect.element(box.getByRole('button', { name: 'Fit to screen (now 150%)' })).toBeVisible()
  await userEvent.keyboard('+')
  await expect.element(box.getByRole('button', { name: 'Fit to screen (now 225%)' })).toBeVisible()
  await userEvent.keyboard('r')
  expect((image.element() as HTMLElement).style.transform).toContain('rotate(90deg)')
  await userEvent.keyboard('0')
  await expect.element(box.getByRole('button', { name: 'Fit to screen (now 100%)' })).toBeVisible()
  await box.getByRole('button', { name: 'Zoom out' }).click()
  await expect.element(box.getByRole('button', { name: 'Fit to screen (now 67%)' })).toBeVisible()
})

test('previous and next move through the files and stop at the ends', async () => {
  const screen = await render(<Harness />)
  const photo = screen.getByRole('dialog', { name: 'photo.png' })
  await expect.element(photo.getByRole('button', { name: 'Previous file' })).toBeDisabled()
  await photo.getByRole('button', { name: 'Next file' }).click()

  const text = screen.getByRole('dialog', { name: 'notes.txt' })
  await expect.element(text.getByTitle('notes.txt')).toBeInTheDocument()
  expect(text.getByRole('button', { name: 'Zoom in' }).query()).toBeNull()
  await expect.element(text.getByText('2 of 3: notes.txt')).toBeInTheDocument()

  await userEvent.keyboard('{End}')
  const archive = screen.getByRole('dialog', { name: 'logs.zip' })
  await expect.element(archive.getByRole('button', { name: 'Next file' })).toBeDisabled()
  await userEvent.keyboard('{Home}')
  await expect.element(screen.getByRole('dialog', { name: 'photo.png' })).toBeVisible()
  await screen
    .getByRole('dialog')
    .getByRole('list', { name: 'Files' })
    .getByRole('button', { name: 'Show logs.zip' })
    .click()
  await expect
    .element(screen.getByRole('button', { name: 'Show logs.zip' }))
    .toHaveAttribute('aria-current', 'true')
})

test('a file without a preview gets a card with its actions', async () => {
  const screen = await render(<Harness start={2} />)
  const box = screen.getByRole('dialog', { name: 'logs.zip' })
  await expect.element(box.getByText(/no preview for this type of file/)).toBeVisible()
  const downloads = box.getByRole('link', { name: 'Download' })
  await expect.element(downloads.first()).toHaveAttribute('href', 'https://files.test/logs.zip')
  expect(box.getByRole('link', { name: 'Open in a new tab' }).query()).toBeNull()
})

test('the details panel toggles, the new-tab link is safe, and Escape closes', async () => {
  const screen = await render(<Harness />)
  const box = screen.getByRole('dialog', { name: 'photo.png' })
  const open = box.getByRole('link', { name: 'Open in a new tab' })
  await expect.element(open).toHaveAttribute('target', '_blank')
  await expect.element(open).toHaveAttribute('rel', 'noopener noreferrer')
  await expect.element(box.getByRole('link', { name: 'Download' })).toHaveAttribute('download', 'photo.png')

  await box.getByRole('button', { name: 'Show details' }).click()
  await expect
    .element(box.getByRole('complementary', { name: 'Details' }))
    .toMatchTextContent(/Dimensions1 × 1/)
  await userEvent.keyboard('i')
  await expect.element(box.getByRole('complementary', { name: 'Details' })).not.toBeInTheDocument()

  const results = await axe.run(document.body)
  expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([])

  await userEvent.keyboard('{Escape}')
  await expect.element(screen.getByRole('dialog')).not.toBeInTheDocument()
  await screen.getByRole('button', { name: 'Open' }).click()
  await expect.element(screen.getByRole('dialog', { name: 'photo.png' })).toBeVisible()
})
