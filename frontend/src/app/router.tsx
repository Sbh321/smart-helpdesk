import type { QueryClient } from '@tanstack/react-query'
import { createRouter, type RouterHistory } from '@tanstack/react-router'
import type { RuntimeConfig } from '@/lib/config'
import { routeTree } from '@/routeTree.gen'

export type RouterContext = {
  queryClient: QueryClient
  config: RuntimeConfig
}

/** `history` is only passed by tests, which use an in-memory history instead of the address bar. */
export function createAppRouter(context: RouterContext, options: { history?: RouterHistory } = {}) {
  return createRouter({
    routeTree,
    context,
    defaultPreload: 'intent',
    scrollRestoration: true,
    ...(options.history ? { history: options.history } : {}),
  })
}

declare module '@tanstack/react-router' {
  interface Register {
    router: ReturnType<typeof createAppRouter>
  }

  /** A route names its own breadcrumb; `components/layout/breadcrumbs.tsx` reads it from the matches. */
  interface StaticDataRouteOption {
    crumb?: string
  }
}
