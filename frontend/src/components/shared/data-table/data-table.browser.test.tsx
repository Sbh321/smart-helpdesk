import '@/styles/globals.css'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { userEvent } from 'vitest/browser'
import { render } from 'vitest-browser-react'
import { copy, fill } from '@/copy/en'
import { columnVisibilityKey } from './column-visibility-storage'
import { DataTable, type DataTableProps } from './data-table'
import { dataTableColumnHelper } from './data-table-columns'
import { DateRangeFilter } from './date-range-filter'

/** The DataTable on its own: no router, the parent owns the state (server mode). */
type Person = { id: string; name: string; team: string }

const helper = dataTableColumnHelper<Person>()
const columns = helper.columns([
  helper.accessor('name', { enableSorting: true, enableHiding: false, meta: { label: 'Name' } }),
  helper.accessor('team', { meta: { label: 'Team' } }),
])
const people: Person[] = Array.from({ length: 5 }, (_, index) => ({
  id: `p${index + 1}`,
  name: `Person ${index + 1}`,
  team: index % 2 === 0 ? 'Red' : 'Blue',
}))

async function renderTable(overrides: Partial<DataTableProps<Person, 'name'>> = {}) {
  const props: DataTableProps<Person, 'name'> = {
    id: 'people-test',
    label: 'People',
    columns,
    data: people,
    rowCount: 5,
    state: { page: 1, per_page: 25, sort: 'name' },
    onStateChange: vi.fn(),
    getRowId: (person) => person.id,
    getRowLabel: (person) => person.name,
    emptyState: <p>Nobody here</p>,
    ...overrides,
  }
  const screen = await render(<DataTable {...props} />)
  return { screen, props, table: screen.getByRole('table', { name: 'People' }) }
}

beforeEach(() => localStorage.removeItem(columnVisibilityKey('people-test')))
afterEach(() => vi.restoreAllMocks())

test('Enter and a click on a row open it; a click on its checkbox does not', async () => {
  const onRowOpen = vi.fn()
  const { table } = await renderTable({ onRowOpen, bulkActions: () => null })
  const second = table.getByRole('row').nth(2)

  ;(table.getByRole('row').nth(1).element() as HTMLElement).focus()
  await userEvent.keyboard('{ArrowDown}')
  await expect.element(second).toHaveFocus()
  await userEvent.keyboard('{Enter}')
  expect(onRowOpen).toHaveBeenLastCalledWith(people[1])

  await table.getByRole('cell', { name: 'Person 4' }).click()
  expect(onRowOpen).toHaveBeenLastCalledWith(people[3])
  expect(onRowOpen).toHaveBeenCalledTimes(2)

  await table.getByRole('checkbox', { name: fill(copy.dataTable.selectRow, { label: 'Person 5' }) }).click()
  expect(onRowOpen).toHaveBeenCalledTimes(2)
  expect(table.element().getAttribute('aria-describedby')).toBeTruthy()
})

test('select-all fills the bulk bar with the page ids and clearing empties it', async () => {
  const seen: string[][] = []
  const { screen, table } = await renderTable({
    bulkActions: ({ ids }) => {
      seen.push(ids)
      return <button type="button">Archive</button>
    },
  })

  await table.getByRole('checkbox', { name: copy.dataTable.selectAll }).click()
  const bar = screen.getByRole('region', { name: copy.dataTable.bulkActions })
  await expect.element(bar.getByText(fill(copy.dataTable.selectedCount, { count: 5 }))).toBeVisible()
  expect(seen.at(-1)).toEqual(['p1', 'p2', 'p3', 'p4', 'p5'])

  await table.getByRole('checkbox', { name: fill(copy.dataTable.selectRow, { label: 'Person 1' }) }).click()
  await expect.element(bar.getByText(fill(copy.dataTable.selectedCount, { count: 4 }))).toBeVisible()
  await expect
    .element(table.getByRole('checkbox', { name: copy.dataTable.selectAll }))
    .toHaveAttribute('aria-checked', 'mixed')

  await bar.getByRole('button', { name: copy.dataTable.clearSelection }).click()
  await expect.element(bar).not.toBeInTheDocument()
})

test('a controlled selection keeps ids of other pages and reports all of them', async () => {
  const onChange = vi.fn()
  const { screen, table } = await renderTable({
    selection: { ids: ['elsewhere', 'p2'], onChange },
    bulkActions: ({ ids }) => <output>{ids.join(' ')}</output>,
  })
  const bar = screen.getByRole('region', { name: copy.dataTable.bulkActions })
  await expect.element(bar.getByText(fill(copy.dataTable.selectedCount, { count: 2 }))).toBeVisible()
  await expect.element(bar.getByText('elsewhere p2')).toBeVisible()
  await expect
    .element(table.getByRole('checkbox', { name: copy.dataTable.selectAll }))
    .toHaveAttribute('aria-checked', 'mixed')

  await table.getByRole('checkbox', { name: copy.dataTable.selectAll }).click()
  expect(onChange).toHaveBeenLastCalledWith(['elsewhere', 'p2', 'p1', 'p3', 'p4', 'p5'])
  await bar.getByRole('button', { name: copy.dataTable.clearSelection }).click()
  expect(onChange).toHaveBeenLastCalledWith([])
})

test('default column visibility hides a column until the viewer turns it on', async () => {
  const { screen, table } = await renderTable({ defaultColumnVisibility: { team: false } })
  expect(table.getByRole('columnheader', { name: 'Team' }).query()).toBeNull()
  await screen.getByRole('button', { name: copy.dataTable.columns }).click()
  await screen.getByRole('menuitemcheckbox', { name: 'Team' }).click()
  await expect.element(table.getByRole('columnheader', { name: 'Team' })).toBeVisible()
  expect(JSON.parse(localStorage.getItem(columnVisibilityKey('people-test')) ?? '{}')).toEqual({ team: true })
})

test('sortable headers carry aria-sort and ask for the next sort; plain headers do not', async () => {
  const onStateChange = vi.fn()
  const { table } = await renderTable({ onStateChange, state: { page: 3, per_page: 25, sort: '-name' } })
  const name = table.getByRole('columnheader', { name: 'Name' })

  await expect.element(name).toHaveAttribute('aria-sort', 'descending')
  expect(table.getByRole('columnheader', { name: 'Team' }).element().hasAttribute('aria-sort')).toBe(false)

  await name.getByRole('button').click()
  expect(onStateChange).toHaveBeenLastCalledWith({ sort: undefined })
})

test('the footer changes the page and the page size', async () => {
  const onStateChange = vi.fn()
  const { screen } = await renderTable({
    onStateChange,
    rowCount: 120,
    state: { page: 2, per_page: 25, sort: 'name' },
  })

  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 26, to: 50, total: 120 })))
    .toBeVisible()
  await expect.element(screen.getByText(fill(copy.dataTable.pageOf, { page: 2, pages: 5 }))).toBeVisible()
  // Row 1 is the header; the first row of page 2 is row 27 of 121.
  const table = screen.getByRole('table', { name: 'People' })
  await expect.element(table).toHaveAttribute('aria-rowcount', '121')
  await expect.element(table.getByRole('row').nth(1)).toHaveAttribute('aria-rowindex', '27')
  await screen.getByRole('button', { name: copy.dataTable.lastPage }).click()
  expect(onStateChange).toHaveBeenLastCalledWith({ page: 5 })
  await screen.getByRole('button', { name: copy.dataTable.previousPage }).click()
  expect(onStateChange).toHaveBeenLastCalledWith({ page: 1 })

  await screen.getByRole('combobox', { name: copy.dataTable.rowsPerPage }).click()
  await screen.getByRole('option', { name: '50' }).click()
  expect(onStateChange).toHaveBeenLastCalledWith({ per_page: 50 })
})

test('first load shows skeleton rows and a busy table', async () => {
  const { screen, table } = await renderTable({ data: undefined, rowCount: undefined })

  await expect.element(table).toHaveAttribute('aria-busy', 'true')
  await expect.element(screen.getByRole('status')).toHaveTextContent(copy.dataTable.loading)
  expect(table.getByRole('row').elements()).toHaveLength(1)
})

test('an empty page past the end offers the first page; an empty list shows the empty state', async () => {
  const onStateChange = vi.fn()
  const { screen } = await renderTable({
    data: [],
    rowCount: 5,
    onStateChange,
    state: { page: 9, per_page: 25, sort: 'name' },
  })

  await screen.getByRole('button', { name: copy.dataTable.pageOutOfRange.action }).click()
  expect(onStateChange).toHaveBeenLastCalledWith({ page: 1 })

  const empty = await renderTable({ data: [], rowCount: 0 })
  await expect.element(empty.screen.getByText('Nobody here')).toBeVisible()
})

test('the table still renders when localStorage throws', async () => {
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new DOMException('denied', 'SecurityError')
  })
  const { table } = await renderTable()
  await expect.element(table.getByRole('columnheader', { name: 'Team' })).toBeVisible()
})

test('the date range filter writes both ends as calendar dates and clears them', async () => {
  const onChange = vi.fn()
  const screen = await render(<DateRangeFilter label="Created" value={undefined} onChange={onChange} />)

  await screen.getByRole('button', { name: 'Created' }).click()
  const days = screen.getByRole('grid').getByRole('button')
  await days.nth(10).click()
  await days.nth(12).click()

  expect(onChange).toHaveBeenCalledTimes(1)
  const range = onChange.mock.calls[0]?.[0] as { from: string; to: string }
  expect(range.from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  expect(range.to).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  expect((Date.parse(range.to) - Date.parse(range.from)) / 86_400_000).toBe(2)

  await screen.rerender(<DateRangeFilter label="Created" value={range} onChange={onChange} />)
  await screen.getByRole('button', { name: /Created/ }).click()
  await screen.getByRole('button', { name: copy.filters.dateRange.clear }).click()
  expect(onChange).toHaveBeenLastCalledWith(undefined)
})
