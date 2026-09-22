import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'
import { stateFor, users } from './support/env'

/**
 * Reports (M3-20, M3-09): Priya opens Backlog over time from the catalogue, the numbers are on the page
 * as a data table, and a CSV export is queued, finishes and downloads from object storage.
 */

test.use({ storageState: stateFor('priya') })

test('a manager opens a report from the catalogue and downloads its CSV export', async ({ page }) => {
  await page.goto(`/${users.priya.workspace}/reports`)
  await page.getByRole('link', { name: /^Backlog over time/ }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Backlog over time' })).toBeVisible()
  await expect(page.getByRole('combobox', { name: 'Period' })).toContainText('Last 30 days')
  const table = page.getByRole('table', { name: 'Backlog over time: data table' })
  await expect(table.getByRole('columnheader', { name: 'Day', exact: true })).toBeVisible()
  await expect(table.getByRole('row')).not.toHaveCount(1)

  await page.getByRole('button', { name: 'Export CSV' }).click()
  const link = page.getByRole('link', { name: /^Download .+\.csv \(\d+ rows\)$/ })
  await expect(link).toBeVisible({ timeout: 30_000 })
  const rows = Number((await link.textContent())?.match(/\((\d+) rows\)/)?.[1])

  const [download] = await Promise.all([page.waitForEvent('download'), link.click()])
  expect(download.suggestedFilename()).toMatch(/\.csv$/)
  const csv = (await readFile(await download.path(), 'utf8')).replace(/^﻿/, '').trim().split(/\r?\n/)
  expect(csv[0]).toMatch(/^"?Day"?,/)
  // Header, one line per data row, and the totals line.
  expect(csv).toHaveLength(rows + 2)
  expect(csv.at(-1)).toMatch(/^"?Total"?,/)
})

test('a number drills down to its tickets, and a ticket shows its state as of three days ago', async ({
  page,
}) => {
  // Ticket volume drills down (Backlog over time does not: its numbers are end-of-day counts).
  await page.goto(`/${users.priya.workspace}/reports/rpt-t01`)
  await expect(page.getByRole('heading', { level: 1, name: 'Ticket volume' })).toBeVisible()
  const number = page
    .getByRole('region', { name: 'Data' })
    .getByRole('button', { name: /^View the records behind [1-9]\d*: .+, Created$/ })
    .last()
  const label = (await number.getAttribute('aria-label')) ?? ''
  const [, value, day] = label.match(/behind (\d+): (\d{4}-\d{2}-\d{2}), Created$/) ?? []
  await number.click()
  const records = page.getByRole('dialog', { name: `Records: ${day}, Created` })
  // The drill-down lists exactly the tickets that measure counts, not every record of the day.
  await expect(records.getByText(`${value} records behind this number.`)).toBeVisible()
  await expect(records.getByRole('list', { name: 'Records' }).getByRole('link').first()).toBeVisible()
  const first = records.getByRole('list', { name: 'Records' }).getByRole('link').first()
  const ticketNumber = (await first.textContent())?.match(/^#(\d+)/)?.[1]
  await first.click()

  await expect(page).toHaveURL(/\/tickets\/[0-9a-f-]{36}$/)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(page.getByText(`#${ticketNumber}`).first()).toBeVisible()
  await page.getByRole('tab', { name: 'History' }).click()
  const asOf = page.getByRole('region', { name: 'As of' })
  const threeDaysAgo = new Date(Date.now() - 3 * 24 * 3600 * 1000)
  const local = new Date(threeDaysAgo.getTime() - threeDaysAgo.getTimezoneOffset() * 60_000)
  await asOf.getByRole('textbox', { name: 'Date and time' }).fill(local.toISOString().slice(0, 16))
  await asOf.getByRole('button', { name: 'Show this state' }).click()
  await expect(
    asOf.getByText(/State on |did not exist on |Nothing recorded has changed since /).first(),
  ).toBeVisible()
})
