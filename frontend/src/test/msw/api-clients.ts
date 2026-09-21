import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { db, NOW, nextId } from './data'
import { apiUrl, problem } from './handlers'
import { validationFailed } from './list'

type StoreInput = components['schemas']['StoreApiClientRequest']
type ScopeResource = components['schemas']['ApiScopeResource']

/** The ScopeMap of the backend (docs/07-api/authentication.md §Scopes → permissions). */
export const API_SCOPES: ScopeResource[] = [
  {
    scope: 'tickets:read',
    description: 'Read tickets, their history and public comments',
    permissions: ['tickets.view'],
  },
  {
    scope: 'tickets:write',
    description: 'Create tickets',
    permissions: ['tickets.create', 'tickets.update', 'tickets.resolve', 'tickets.close'],
  },
  { scope: 'contacts:read', description: 'Read contacts and organisations', permissions: ['contacts.view'] },
  {
    scope: 'contacts:write',
    description: 'Create and update contacts and organisations',
    permissions: ['contacts.manage'],
  },
  {
    scope: 'catalog:read',
    description: 'Read agents, teams, categories and SLA policies',
    permissions: ['agents.view'],
  },
  {
    scope: 'webhooks:manage',
    description: 'Manage webhook subscriptions',
    permissions: ['integrations.manage'],
  },
]

/** The secret the create handler returns; tests look for it on screen. */
export const TEST_CLIENT_SECRET = 'mock-secret-0123456789abcdefghijklmnopqrstuvwxyzAB'

export const apiClientHandlers = [
  http.get(apiUrl('/api-clients'), () =>
    HttpResponse.json({
      data: [...db.apiClients].sort(
        (a, b) => Number(a.revoked) - Number(b.revoked) || b.created_at.localeCompare(a.created_at),
      ),
    }),
  ),
  http.get(apiUrl('/api-clients/scopes'), () => HttpResponse.json({ data: API_SCOPES })),
  http.post(apiUrl('/api-clients'), async ({ request }) => {
    const input = (await request.json()) as StoreInput
    const errors: Record<string, string[]> = {}
    if (!input.name || input.name.trim() === '') errors.name = ['The name field is required.']
    if (!Array.isArray(input.scopes) || input.scopes.length === 0)
      errors.scopes = ['The scopes field is required.']
    if (Object.keys(errors).length > 0) return validationFailed(errors)

    const client = {
      id: nextId(0xa0),
      name: input.name.trim(),
      scopes: input.scopes,
      revoked: false,
      revoked_at: null,
      last_used_at: null,
      created_at: NOW,
    }
    db.apiClients.push(client)
    return HttpResponse.json(
      { data: { ...client, client_id: client.id, client_secret: TEST_CLIENT_SECRET } },
      { status: 201 },
    )
  }),
  http.post(apiUrl('/api-clients/{apiClient}/revoke'), ({ params }) => {
    const client = db.apiClients.find((item) => item.id === params.apiClient)
    if (!client) return problem(404, 'not_found', { title: 'Not found' })
    client.revoked = true
    client.revoked_at ??= NOW
    return HttpResponse.json({ data: client })
  }),
]
