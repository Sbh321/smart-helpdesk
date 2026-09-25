import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ParsedLocation } from '@tanstack/react-router'
import { createMemoryHistory, RouterProvider } from '@tanstack/react-router'
import type { RenderResult } from 'vitest-browser-react'
import { render } from 'vitest-browser-react'
import { createAppRouter } from '@/app/router'
import { initApiClient } from '@/lib/api/client'
import type { RuntimeConfig } from '@/lib/config'
import { ThemeProvider } from '@/lib/theme'
import { TEST_API_ORIGIN } from './msw/handlers'

/** The runtime configuration the proxy would serve, pointed at the MSW origin. */
export const testConfig: RuntimeConfig = {
  apiBaseUrl: TEST_API_ORIGIN,
  appMode: 'tenant',
  platformDomain: 'shp.test',
  storagePublicEndpoint: 'https://files.shp.test',
  docsUrl: 'https://docs.shp.test',
  platformDocsUrl: 'https://platform-docs.shp.test',
  realtime: { enabled: false },
}

/**
 * Renders the real application at a path, with the real router, query client and providers, so browser
 * tests exercise route guards and data fetching rather than a component in isolation.
 */
export async function renderApp(
  path: string,
  /** Runtime config overrides, e.g. `realtime` for the live-update tests (src/test/fake-echo.ts). */
  options: { config?: Partial<RuntimeConfig> } = {},
): Promise<{
  screen: RenderResult
  queryClient: QueryClient
  currentPath: () => string
  currentLocation: () => ParsedLocation
  /** The router itself, for history navigation (`router.history.back()`). */
  router: ReturnType<typeof createAppRouter>
}> {
  const config: RuntimeConfig = { ...testConfig, ...options.config }
  initApiClient(config)
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const router = createAppRouter(
    { queryClient, config },
    { history: createMemoryHistory({ initialEntries: [path] }) },
  )

  const screen = await render(
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>
    </ThemeProvider>,
  )

  return {
    screen,
    queryClient,
    currentPath: () => router.state.location.pathname,
    currentLocation: () => router.state.location,
    router,
  }
}
