// Scripted rehearsal of the 22-minute demo (docs/12-academic/demo-plan.md) against the Compose stack.
// Runs every step of the script with Playwright, saves one screenshot per step and prints how long each
// step took and what did not work, so the presenter can rehearse and the rough edges can be triaged.
//
//   just demo-reset
//   node tools/demo-rehearsal/rehearse.mjs [--out docs/12-academic/screenshots] [--base https://app.shp.localhost]
//
// Needs the frontend dependencies (`pnpm -C frontend install`, Playwright's Chromium) and `docker compose`
// on the PATH for the demo:tick step. The dataset must be fresh: the script creates a ticket and ticks the
// SLA clock, so reset again before the real demo.
import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const require = createRequire(join(root, 'frontend/package.json'))
const { chromium } = require('playwright')

const args = process.argv.slice(2)
const option = (name, fallback) => {
  const index = args.indexOf(`--${name}`)
  return index >= 0 ? args[index + 1] : fallback
}
const out = resolve(root, option('out', 'docs/12-academic/screenshots'))
const base = option('base', 'https://app.shp.localhost')
const domain = new URL(base).hostname.replace(/^app\./, '')
const run = option('run', '1')
const password = option('password', 'password')
const golden = {
  title: 'Cannot login after password reset',
  description: 'After the password reset the portal login fails with ERR-401 and I cannot log in.',
}
mkdirSync(out, { recursive: true })

const results = []
let shot = 0

async function step(name, page, fn) {
  const started = Date.now()
  const notes = []
  let ok = true
  try {
    await fn(notes)
  } catch (error) {
    ok = false
    notes.push(String(error.message ?? error).split('\n')[0])
  }
  shot++
  const file = `${String(shot).padStart(2, '0')}-${name}.png`
  try {
    await page.screenshot({ path: join(out, file) })
  } catch {
    notes.push('screenshot failed')
  }
  const seconds = ((Date.now() - started) / 1000).toFixed(1)
  results.push({ step: name, ok, seconds, notes, file })
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name} (${seconds} s)${notes.length ? ` — ${notes.join('; ')}` : ''}`)
}

async function signIn(page, workspace, email) {
  await page.goto(`${base}/${workspace}/login`)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL(new RegExp(`/${workspace}/?$`), { timeout: 15000 })
}

function compose(...command) {
  return execFileSync('docker', ['compose', ...command], { cwd: root, encoding: 'utf8' })
}

const browser = await chromium.launch()
const context = () =>
  browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 }, colorScheme: 'light' })
// Offline check: every request must stay on the platform's own hosts.
const external = new Set()
const page = async () => {
  const created = await (await context()).newPage()
  created.on('request', (request) => {
    const host = new URL(request.url()).hostname
    if (host !== domain && !host.endsWith(`.${domain}`) && !['localhost', '127.0.0.1'].includes(host) && !request.url().startsWith('data:'))
      external.add(host)
  })
  return created
}
const admin = await page()
const priya = await page()
const arjun = await page()
const meera = await page()
const sam = await page()

// 0–1 Platform admin: two workspaces. The console page is a placeholder (V1-PL-13), so the list comes
// from the platform API on the admin host, signed in with the platform session.
await step('platform-admin', admin, async (notes) => {
  const host = `https://admin.${domain}`
  await admin.request.get(`${host}/platform-api/csrf-cookie`)
  // The platform console has its own CSRF cookie and header (ValidatePlatformCsrfToken).
  const xsrf = decodeURIComponent((await admin.context().cookies()).find((c) => c.name === 'XSRF-TOKEN-PLATFORM')?.value ?? '')
  const login = await admin.request.post(`${host}/platform-api/auth/login`, {
    headers: { Accept: 'application/json', 'X-XSRF-TOKEN-PLATFORM': xsrf, Origin: host, Referer: `${host}/` },
    data: { email: 'admin@platform.test', password },
  })
  notes.push(`platform login ${login.status()}`)
  await admin.goto(`${host}/platform-api/tenants`)
  const text = await admin.locator('body').innerText()
  const slugs = [...text.matchAll(/"slug":\s*"([^"]+)"/g)].map((m) => m[1])
  notes.push(`tenants: ${slugs.join(', ') || 'none'}`)
  if (!slugs.includes('acme') || !slugs.includes('globex')) throw new Error('acme and globex not listed')
})

// 1–3 Priya's dashboard.
await step('dashboard', priya, async (notes) => {
  await signIn(priya, 'acme', 'priya@acme.test')
  await priya.getByRole('heading', { level: 1 }).first().waitFor()
  await priya.waitForLoadState('networkidle')
  const charts = await priya.locator('svg.recharts-surface').count()
  if (charts < 6) notes.push(`${charts} charts drawn`)
})

// 3–5 Arjun creates the golden-path ticket; the duplicate suggestion appears before saving.
let ticketUrl = null
await step('duplicate-preview', arjun, async (notes) => {
  await signIn(arjun, 'acme', 'arjun@acme.test')
  await arjun.goto(`${base}/acme/tickets`)
  await arjun.getByRole('button', { name: 'New ticket' }).first().click()
  const dialog = arjun.getByRole('dialog', { name: 'New ticket' })
  await dialog.getByLabel('Title').fill(golden.title)
  await dialog.getByLabel('Description').fill(golden.description)
  await dialog.getByText('#1031').first().waitFor({ timeout: 10000 })
  await dialog.getByText('#1031').first().scrollIntoViewIfNeeded()
  const text = await dialog.innerText()
  const score = text.match(/(0\.\d+|\d+ ?%)/)
  notes.push(`#1031 suggested${score ? ` (${score[1]})` : ''}`)
})

await step('ticket-created', arjun, async (notes) => {
  const dialog = arjun.getByRole('dialog', { name: 'New ticket' })
  await dialog.getByRole('combobox', { name: 'Contact' }).fill('Laura')
  await arjun.getByRole('option', { name: /Laura Schmidt/ }).first().click()
  await dialog.getByRole('combobox', { name: 'Category' }).click()
  await arjun.getByRole('option', { name: 'Account access' }).click()
  await dialog.getByRole('combobox', { name: 'Impact' }).click()
  await arjun.getByRole('option', { name: /^2 · / }).click()
  await dialog.getByRole('combobox', { name: 'Urgency' }).click()
  await arjun.getByRole('option', { name: /^3 · / }).click()
  await dialog.getByRole('button', { name: 'Create ticket' }).click()
  const created = await arjun.getByText(/^Ticket #\d+ created\.$/).textContent({ timeout: 10000 })
  const number = created?.match(/#(\d+)/)?.[1]
  notes.push(`#${number}`)
  await arjun.getByRole('searchbox').first().fill(golden.title)
  await arjun.getByRole('cell', { name: `#${number}`, exact: true }).click()
  await arjun.waitForURL(/\/tickets\/[0-9a-f-]{36}$/)
  ticketUrl = arjun.url()
  await arjun.getByRole('heading', { level: 1, name: new RegExp(golden.title) }).waitFor()
  await arjun.waitForLoadState('networkidle')
})

// 5–7 Priority "Why?", assignment ranking, SLA panel.
await step('why-priority', arjun, async (notes) => {
  await arjun.getByRole('button', { name: 'Why this priority?' }).click()
  const panel = arjun.getByText('basic_weighted_priority', { exact: false }).first()
  await panel.waitFor()
  const level = await arjun.getByText(/^P[1-4] /).first().textContent()
  notes.push(`level ${level}`)
})

await step('assignment-and-sla', arjun, async (notes) => {
  await arjun.keyboard.press('Escape')
  const main = await arjun.locator('main').innerText()
  const agent = main.match(/Agent\s*\n\s*([^\n]+)/)
  if (agent) notes.push(`agent ${agent[1].trim()}`)
  if (!/least_loaded_agent|Eligible Agents|How Agents are ranked/i.test(main))
    notes.push('assignment ranking not visible on the ticket page without opening a panel')
  if (!/First response|first_response/i.test(main)) notes.push('SLA panel not found')
})

// The stored ranking of an automatic assignment is not shown on an assigned ticket (rough edge), so
// the ranking is shown on one of the tickets waiting for triage: Assignment → eligible Agents and why
// the others are excluded (Elena away, Grace offline, missing skills, at capacity).
await step('assignment-ranking', priya, async (notes) => {
  const xsrf = decodeURIComponent((await priya.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, Origin: `https://app.${domain}` }
  const categories = await (await priya.request.get(`https://api.${domain}/v1/categories`, { headers })).json()
  const account = (categories.data ?? []).find((c) => c.name === 'Account access')?.id
  const list = await (await priya.request.get(`https://api.${domain}/v1/tickets?filter[status]=open&per_page=50`, { headers })).json()
  const open = (list.data ?? []).find((t) => (t.category_id ?? t.category?.id) === account) ?? (list.data ?? [])[0]
  notes.push(`#${open.number} (${open.status})`)
  await priya.goto(`${base}/acme/tickets/${open.id}`)
  await priya.getByRole('button', { name: 'Assignment', exact: true }).click()
  const dialog = priya.getByRole('dialog').first()
  await dialog.getByText(/Eligible Agents|No eligible Agents/).first().waitFor({ timeout: 10000 })
  const text = await dialog.innerText()
  notes.push((text.match(/Excluded Agents \(\d+\)/) ?? ['no exclusions listed'])[0])
})

// 7–9 Internal note, public reply; Mailpit gets the contact email; first response met.
await priya.keyboard.press('Escape')
await step('reply-and-mail', arjun, async (notes) => {
  await arjun.getByRole('tab', { name: 'Comments' }).click()
  await arjun.getByRole('radio', { name: 'Internal note' }).check()
  await arjun.getByRole('textbox', { name: 'Message' }).fill('Checked the auth logs: ERR-401 after the reset token was used.')
  await arjun.getByRole('button', { name: 'Send comment' }).click()
  await arjun.getByText('Checked the auth logs').first().waitFor()
  await arjun.waitForTimeout(1000)
  await arjun.getByRole('radio', { name: 'Public reply' }).check()
  await arjun.getByRole('textbox', { name: 'Message' }).fill('Thanks Laura, we have reset your session. Please try signing in again.')
  await arjun.getByRole('button', { name: 'Send comment' }).click()
  await arjun.getByText('we have reset your session').first().waitFor()
  await arjun.waitForTimeout(4000)
  try {
    const messages = JSON.parse(compose('exec', '-T', 'app', 'curl', '-s', 'http://mailpit:8025/api/v1/messages?limit=5'))
    const hit = (messages.messages ?? []).find((m) => (m.To ?? []).some((t) => /laura\.schmidt/.test(t.Address)))
    notes.push(hit ? `Mailpit: "${hit.Subject}"` : 'no email to the contact in Mailpit yet')
  } catch (error) {
    notes.push(`Mailpit not checked: ${String(error.message).split('\n')[0]}`)
  }
})

// 9–10 Pending pauses the SLA; resume.
await step('pending-paused', arjun, async (notes) => {
  await arjun.getByRole('button', { name: 'Set pending' }).click()
  const confirm = arjun.getByRole('dialog').getByRole('button', { name: 'Confirm' })
  if (await confirm.isVisible().catch(() => false)) await confirm.click()
  await arjun.getByText('Pending', { exact: true }).first().waitFor()
  await arjun.getByText('Paused', { exact: false }).first().waitFor({ timeout: 5000 }).catch(() => {})
  await arjun.getByText('Paused', { exact: false }).first().scrollIntoViewIfNeeded({ timeout: 2000 }).catch(() => {})
  const main = await arjun.locator('main').innerText()
  notes.push(/paused/i.test(main) ? 'timer shows paused' : 'no "paused" wording on the page')
})

await step('resumed', arjun, async () => {
  await arjun.getByRole('button', { name: /Resume work|Start work/ }).first().click()
  const confirm = arjun.getByRole('dialog').getByRole('button', { name: 'Confirm' })
  if (await confirm.isVisible().catch(() => false)) await confirm.click()
  await arjun.getByText('In progress', { exact: true }).first().waitFor()
  await arjun.waitForTimeout(1500)
  await arjun.getByRole('heading', { name: 'SLA' }).first().scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {})
})

// 10–12 A near-breach ticket; demo:tick 30; warning and breach.
await step('near-breach-before', priya, async (notes) => {
  await priya.goto(`${base}/acme/tickets`)
  await priya.getByRole('searchbox').first().fill('seat limit')
  await priya.getByRole('cell', { name: '#1104', exact: true }).click()
  await priya.waitForURL(/\/tickets\/[0-9a-f-]{36}$/)
  await priya.waitForLoadState('networkidle')
  const main = await priya.locator('main').innerText()
  const due = main.match(/(due[^\n]*)/i)
  if (due) notes.push(due[1].slice(0, 80))
})

await step('near-breach-after-tick', priya, async (notes) => {
  const output = compose('exec', '-T', 'app', 'php', 'artisan', 'demo:tick', '30')
  notes.push(output.split('\n').find((line) => /acme/.test(line))?.replace(/\s+/g, ' ').trim() ?? 'tick ran')
  await priya.waitForTimeout(8000)
  await priya.reload()
  await priya.waitForLoadState('networkidle')
  const main = await priya.locator('main').innerText()
  notes.push(/breach/i.test(main) ? 'breach shown' : 'no breach wording after the tick')
  await priya.getByRole('heading', { name: 'SLA' }).first().scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {})
})

await step('notifications', priya, async (notes) => {
  await priya.getByRole('button', { name: /Notifications/ }).first().click()
  await priya.waitForTimeout(1500)
  const body = await priya.locator('body').innerText()
  notes.push(/breach/i.test(body) ? 'breach notification listed' : 'no breach notification visible')
})

// 12–14 Settings → Automation: change a weight and preview. Workspace settings need settings.manage,
// which the Support Manager role does not have: Meera (owner) takes over for steps 12–17.
await step('priority-settings-preview', meera, async (notes) => {
  const priya = meera
  await signIn(meera, 'acme', 'meera@acme.test')
  await priya.goto(`${base}/acme/settings/priority`)
  await priya.getByRole('heading', { name: 'Priority automation' }).waitFor()
  await priya.getByRole('button', { name: 'Preview scores' }).click()
  await priya.waitForTimeout(1500)
  const before = await priya.locator('main').innerText()
  const impact = priya.getByRole('spinbutton', { name: 'Impact' }).first()
  const tier = priya.getByRole('spinbutton', { name: 'Organisation tier' }).first()
  await impact.fill('0.30')
  await tier.fill('0.25')
  await priya.getByRole('button', { name: 'Preview scores' }).click()
  await priya.waitForTimeout(1500)
  const after = await priya.locator('main').innerText()
  notes.push(before === after ? 'preview did not change' : 'scores changed in the preview (not saved)')
})

// 14–16 Developer platform: API client, webhook and delivery log; resolve the ticket → webhook-echo.
await step('api-clients', meera, async () => {
  await meera.goto(`${base}/acme/settings/api-clients`)
  await meera.getByText('Monitoring bridge').first().waitFor({ timeout: 10000 })
})

await step('webhooks-deliveries', meera, async (notes) => {
  await meera.goto(`${base}/acme/settings/webhooks`)
  await meera.getByRole('button', { name: /Deliveries|Show deliveries/ }).first().click()
  await meera.waitForTimeout(1500)
  const text = await meera.locator('body').innerText()
  notes.push(`${(text.match(/dead/gi) ?? []).length} dead, retry ${/Retry/.test(text) ? 'shown' : 'not shown'}`)
})

await step('resolve-webhook-echo', arjun, async (notes) => {
  await arjun.goto(ticketUrl)
  await arjun.getByRole('button', { name: 'Resolve ticket' }).click()
  const dialog = arjun.getByRole('dialog', { name: 'Resolve ticket' })
  await dialog.getByRole('textbox', { name: 'Resolution comment' }).fill('Cleared the stale reset token; sign-in works again.')
  await dialog.getByRole('button', { name: 'Confirm' }).click()
  await arjun.getByText('Resolved', { exact: true }).first().waitFor()
  await arjun.waitForTimeout(6000)
  const logs = compose('logs', '--since', '2m', '--no-log-prefix', 'webhook-echo')
  const resolved = logs.split('\n').filter((line) => line.includes('"ticket.resolved"'))
  const verified = resolved.some((line) => line.includes('"verified":true'))
  notes.push(resolved.length ? `webhook-echo got ticket.resolved (verified ${verified})` : 'webhook-echo got no ticket.resolved')
  if (!verified) throw new Error('no verified ticket.resolved delivery')
})

// 16–17 API reference.
await step('api-docs', meera, async () => {
  await meera.goto(`https://docs.${domain}/`)
  await meera.waitForLoadState('networkidle')
  await meera.getByText(/tickets/i).first().waitFor({ timeout: 15000 })
})

// 17–18 Globex cannot open the Acme ticket.
await step('isolation-404', sam, async (notes) => {
  await signIn(sam, 'globex', 'sam@globex.test')
  const id = ticketUrl.split('/').pop()
  await sam.goto(`${base}/globex/tickets/${id}`)
  await sam.waitForLoadState('networkidle')
  await sam.waitForTimeout(4000)
  const text = await sam.locator('main').innerText()
  notes.push(/not found|404|does not exist|no longer exists/i.test(text) ? 'not found shown' : `page says: ${text.slice(0, 80).replace(/\s+/g, ' ')}`)
  const api = await sam.request.get(`https://api.${domain}/v1/tickets/${id}`, {
    headers: { Accept: 'application/json', Origin: `https://app.${domain}` },
  })
  notes.push(`API ${api.status()}`)
  if (api.status() !== 404) throw new Error(`expected 404, got ${api.status()}`)
})

// (+2) Reports: backlog over time, drill-down, a ticket's history as of three days ago.
await step('report-backlog', priya, async (notes) => {
  await priya.goto(`${base}/acme/reports`)
  await priya.getByRole('link', { name: /Backlog over time/i }).first().click()
  await priya.waitForLoadState('networkidle')
  await priya.locator('svg.recharts-surface').first().waitFor({ timeout: 15000 })
  notes.push(priya.url().replace(base, ''))
})

// Backlog over time has no drill-down; the ticket volume report does (click a day's count).
await step('report-volume', priya, async (notes) => {
  await priya.goto(`${base}/acme/reports`)
  await priya.getByRole('link', { name: /Ticket volume/i }).first().click()
  await priya.waitForLoadState('networkidle')
  await priya.locator('svg.recharts-surface').first().waitFor({ timeout: 15000 })
  notes.push(priya.url().replace(base, ''))
})

await step('report-drill-down', priya, async (notes) => {
  const cell = priya.locator('table tbody button').filter({ hasText: /^[1-9]\d*$/ }).last()
  await cell.scrollIntoViewIfNeeded()
  await cell.click()
  await priya.getByRole('dialog').first().waitFor({ timeout: 10000 })
  await priya.getByRole('dialog').getByText(/records? behind this number/).first().waitFor({ timeout: 10000 })
  const summary = await priya.getByRole('dialog').getByText(/records? behind this number/).first().textContent()
  notes.push(summary ?? '')
})

await step('history-as-of', priya, async (notes) => {
  await priya.goto(`${base}/acme/tickets`)
  await priya.getByRole('searchbox').first().fill('Login fails after resetting password')
  await priya.getByRole('cell', { name: '#1031', exact: true }).click()
  await priya.waitForURL(/\/tickets\/[0-9a-f-]{36}$/)
  await priya.getByRole('tab', { name: 'History' }).click()
  const input = priya.getByLabel('Date and time')
  const then = new Date(Date.now() - 3 * 86400000)
  const local = new Date(then.getTime() + 345 * 60000).toISOString().slice(0, 16) // Asia/Kathmandu, +05:45
  await input.fill(local)
  await priya.getByRole('button', { name: 'Show this state' }).click()
  await priya.waitForTimeout(2000)
  const text = await priya.locator('main').innerText()
  notes.push(/did not exist/i.test(text) ? 'did not exist then (history missing)' : /State on/i.test(text) ? 'state shown' : 'no as-of result')
})

// 19–20 Dark mode and a keyboard-driven dialog.
await step('dark-mode-keyboard', priya, async () => {
  await priya.getByRole('button', { name: /Dark/ }).first().click()
  await priya.goto(`${base}/acme/tickets`)
  await priya.getByRole('button', { name: 'New ticket' }).first().focus()
  await priya.keyboard.press('Enter')
  await priya.getByRole('dialog', { name: 'New ticket' }).waitFor()
  await priya.keyboard.press('Tab')
  await priya.keyboard.press('Tab')
})

await browser.close()

// Smaller files for the repository: 256-colour palettes (screenshots of text compress well), when
// Python with Pillow is available; otherwise the PNGs stay as Chromium wrote them.
try {
  const files = results.map((r) => join(out, r.file))
  execFileSync('python3', ['-c', 'import sys\nfrom PIL import Image\nfor f in sys.argv[1:]:\n    Image.open(f).convert("RGB").quantize(256, method=2).save(f, optimize=True)', ...files])
} catch {
  console.log('(screenshots not compressed: python3 with Pillow not found)')
}

const failed = results.filter((r) => !r.ok)
console.log(external.size ? `External hosts contacted: ${[...external].join(', ')}` : 'No request left the platform hosts (offline-safe).')
results.push({ step: 'offline-check', ok: external.size === 0, seconds: '0', notes: [...external], file: '' })
writeFileSync(join(out, `rehearsal-${run}.json`), `${JSON.stringify(results, null, 2)}\n`)
console.log(`\n${results.length - failed.length}/${results.length} steps ok; total ${results.reduce((s, r) => s + Number(r.seconds), 0).toFixed(0)} s`)
process.exit(failed.length ? 1 : 0)
