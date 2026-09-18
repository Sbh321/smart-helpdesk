import { createFileRoute, redirect } from '@tanstack/react-router'
import { ensureSession } from '@/features/auth'
import { guardAuthRoute } from '@/lib/auth'

/**
 * Pre-authentication pages. Somebody who already has a session has no business on the sign-in screen,
 * so they are sent on to where they were heading.
 */
export const Route = createFileRoute('/$workspace/_auth')({
  beforeLoad: async ({ context, params, location }) => {
    const session = await ensureSession(context.queryClient)
    const search = location.search as Record<string, unknown>
    const decision = guardAuthRoute({ session, workspace: params.workspace, redirect: search.redirect })
    if (decision.kind === 'signed-in') {
      throw redirect({ href: decision.href, replace: true })
    }
  },
})
