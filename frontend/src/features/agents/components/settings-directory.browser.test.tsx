import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { AGENT_FIXTURES } from '@/test/msw/agents'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

test('a manager creates a Skill from Settings and sees it in the list', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['agents.view', 'agents.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/skills')
  await screen.getByRole('button', { name: 'Add skill' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('PostgreSQL')
  await screen.getByRole('button', { name: 'Save skill' }).click()
  await expect.element(screen.getByText('PostgreSQL')).toBeVisible()
  await expect.element(screen.getByText('Changes saved.')).toBeVisible()
})

test('a rejected Skill exposes its field error beside the form', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['agents.view', 'agents.manage'] }) }),
    ),
    http.post(apiUrl('/skills'), () =>
      problem(422, 'validation_failed', { errors: { name: ['The name has already been taken.'] } }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/skills')
  await screen.getByRole('button', { name: 'Add skill' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('Billing')
  await screen.getByRole('button', { name: 'Save skill' }).click()
  await expect.element(screen.getByText('The name has already been taken.')).toBeVisible()
})

test('the Skill table restores its search from the URL', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['agents.view'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/skills?search=Networking')
  await expect.element(screen.getByRole('table', { name: 'Skills' })).toBeVisible()
  await expect.element(screen.getByText('Networking')).toBeVisible()
  await expect.element(screen.getByText('Billing')).not.toBeInTheDocument()
})

test('a manager selects a default Team when creating a Category', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'settings.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/categories')
  await screen.getByRole('button', { name: 'Add category' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('Escalations')
  await screen.getByRole('combobox', { name: 'Default team' }).fill('Technical')
  await screen.getByRole('option', { name: 'Technical' }).click()
  await screen.getByRole('button', { name: 'Save category' }).click()
  await expect.element(screen.getByText('Escalations')).toBeVisible()
})

test('a manager adds a Team member using search', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['agents.view', 'teams.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/teams')
  await screen.getByRole('button', { name: 'Add team' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('Escalations')
  await screen.getByRole('combobox', { name: 'Members' }).fill('Asha')
  await screen.getByRole('option', { name: /Asha Rai/ }).click()
  await screen.getByRole('button', { name: 'Save team' }).click()
  await expect
    .poll(() => db.teams.find((team) => team.name === 'Escalations')?.members[0]?.name)
    .toBe('Asha Rai')
})

test('a manager edits Agent skill levels with searchable Skills', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['agents.view', 'agents.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/agents')
  await screen.getByRole('button', { name: 'Edit Chen Gurung' }).click()
  await screen.getByRole('combobox', { name: 'Skills' }).fill('Networking')
  await screen.getByRole('option', { name: 'Networking' }).click()
  await screen.getByRole('spinbutton', { name: 'Networking · Skill level' }).fill('5')
  await screen.getByRole('button', { name: 'Save agent' }).click()
  await expect
    .poll(
      () =>
        db.agents
          .find((agent) => agent.user.name === 'Chen Gurung')
          ?.skills.find((skill) => skill.name === 'Networking')?.level,
    )
    .toBe(5)
})

test('a user without directory permission sees a forbidden state', async () => {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions: [] }) })))
  const { screen } = await renderApp('/acme/settings/agents')
  await expect.element(screen.getByText(/permission/i)).toBeVisible()
})

test('saving a loaded shift schedule preserves the existing rows', async () => {
  const agent = AGENT_FIXTURES[0]
  if (!agent) throw new Error('Missing Agent fixture')
  db.agentShifts[agent.id] = [
    {
      id: 'existing-shift',
      weekday: 1,
      date: null,
      starts_at: '09:00',
      ends_at: '17:00',
      is_off: false,
    },
  ]
  let saved: unknown
  worker.use(
    http.put(apiUrl('/agents/{agent}/shifts'), async ({ request }) => {
      saved = await request.json()
      return HttpResponse.json({ data: db.agentShifts[agent.id] })
    }),
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: ['agents.view', 'shifts.manage'],
          agent_profile: {
            id: agent.id,
            capacity: agent.capacity,
            availability: agent.availability,
            active_ticket_count: agent.active_ticket_count,
          },
        }),
      }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/shifts')
  await expect.element(screen.getByText(/09:00–17:00/)).toBeVisible()
  await screen.getByRole('button', { name: 'Save shifts' }).click()
  await expect
    .poll(() => saved)
    .toMatchObject({ shifts: [{ weekday: 1, starts_at: '09:00', ends_at: '17:00' }] })
})

test('the weekly grid adds a day to the complete Agent schedule', async () => {
  const agent = AGENT_FIXTURES[0]
  if (!agent) throw new Error('Missing Agent fixture')
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: ['agents.view', 'shifts.manage'],
          agent_profile: {
            id: agent.id,
            capacity: agent.capacity,
            availability: agent.availability,
            active_ticket_count: agent.active_ticket_count,
          },
        }),
      }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/shifts')
  await screen.getByRole('button', { name: 'Add Tuesday shift' }).click()
  await screen.getByRole('button', { name: 'Save shifts' }).click()
  await expect.poll(() => db.agentShifts[agent.id]?.[0]?.weekday).toBe(2)
})

test('the Skill form validates on the client and derives the slug from the name', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['agents.view', 'agents.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/skills')
  await screen.getByRole('button', { name: 'Add skill' }).click()
  await screen.getByRole('button', { name: 'Save skill' }).click()
  await expect.element(screen.getByText('Enter a name.')).toBeVisible()
  await expect.element(screen.getByRole('textbox', { name: 'Name' })).toHaveAttribute('aria-invalid', 'true')
  await screen.getByRole('textbox', { name: 'Name' }).fill('C++ & Rust!')
  await expect.element(screen.getByRole('textbox', { name: 'Slug' })).toHaveValue('c-rust')
  await screen.getByRole('textbox', { name: 'Slug' }).fill('Not A Slug')
  await expect.element(screen.getByText(/lowercase letters and digits/)).toBeVisible()
})

test('a rejected Agent capacity is shown on its field', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['agents.view', 'agents.manage'] }) }),
    ),
    http.patch(apiUrl('/agents/{agent}'), () =>
      problem(422, 'validation_failed', {
        errors: { capacity: ['The capacity is below the open tickets.'] },
      }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/agents')
  await screen.getByRole('button', { name: 'Edit Chen Gurung' }).click()
  const capacity = screen.getByRole('spinbutton', { name: 'Capacity' })
  await capacity.fill('0')
  await screen.getByRole('button', { name: 'Save agent' }).click()
  await expect.element(capacity).toHaveAccessibleDescription('Enter a whole number from 1 to 100.')
  await capacity.fill('2')
  await screen.getByRole('button', { name: 'Save agent' }).click()
  await expect.element(capacity).toHaveAccessibleDescription('The capacity is below the open tickets.')
})

test('a shift that ends before it starts is refused on its row', async () => {
  const agent = AGENT_FIXTURES[0]
  if (!agent) throw new Error('Missing Agent fixture')
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: ['agents.view', 'shifts.manage'],
          agent_profile: {
            id: agent.id,
            capacity: agent.capacity,
            availability: agent.availability,
            active_ticket_count: agent.active_ticket_count,
          },
        }),
      }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/shifts')
  await screen.getByRole('button', { name: 'Add Monday shift' }).click()
  await screen.getByLabelText('Ends at').fill('08:00')
  await screen.getByRole('button', { name: 'Save shifts' }).click()
  await expect
    .element(screen.getByLabelText('Ends at'))
    .toHaveAccessibleDescription('End time must be after start time.')
  expect(db.agentShifts[agent.id] ?? []).toHaveLength(0)
})
