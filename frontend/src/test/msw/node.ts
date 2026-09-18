import { setupServer } from 'msw/node'
import { afterAll, afterEach, beforeAll } from 'vitest'
import { contactHandlers } from './contacts'
import { resetMockData } from './data'
import { handlers } from './handlers'
import { ticketHandlers } from './tickets'

/** MSW server for Node unit tests. Unhandled requests fail the test. */
export const server = setupServer(...handlers, ...contactHandlers, ...ticketHandlers)

/** Call at the top of a test file: starts the server and resets per-test overrides. */
export function setupMswServer(): typeof server {
  beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
  afterEach(() => {
    server.resetHandlers()
    resetMockData()
  })
  afterAll(() => server.close())
  return server
}
