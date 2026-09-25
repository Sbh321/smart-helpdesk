import { createRootRouteWithContext, Outlet } from '@tanstack/react-router'
import type { RouterContext } from '@/app/router'
import { ErrorState } from '@/components/shared/error-state'
import { NotFoundState } from '@/components/shared/not-found-state'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import { SessionProvider } from '@/features/auth'

export const Route = createRootRouteWithContext<RouterContext>()({
  component: RootLayout,
  notFoundComponent: () => (
    <div className="mx-auto flex min-h-dvh max-w-xl items-center p-6">
      <NotFoundState />
    </div>
  ),
  errorComponent: ({ error, reset }) => (
    <div className="mx-auto flex min-h-dvh max-w-xl items-center p-6">
      <ErrorState error={error} onRetry={reset} />
    </div>
  ),
})

/**
 * The session lives here, inside the router, so route components and the shell read one `GET /v1/me`
 * through `useSession()` while guards read the same query from the cache. One `TooltipProvider` gives every
 * `Hint` the same delay, and moving between neighbouring triggers opens the next one at once.
 */
function RootLayout() {
  return (
    <TooltipProvider delay={300}>
      <SessionProvider>
        <Outlet />
        <Toaster position="bottom-right" />
      </SessionProvider>
    </TooltipProvider>
  )
}
