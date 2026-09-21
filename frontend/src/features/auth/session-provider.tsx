import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { type ReactNode, useEffect } from 'react'
import { toast } from 'sonner'
import { copy } from '@/copy/en'
import { SessionContext, type SessionContextValue, type SessionStatus } from '@/lib/auth'
import { useTheme } from '@/lib/theme'
import { logout } from './api/auth-requests'
import { clearSession, reloadSession, sessionQuery } from './api/session-queries'

/**
 * Owns the session for the whole application: one `GET /v1/me` query, shared with the route guards
 * through the query cache, so a guarded navigation does not fetch twice.
 */
export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data, isPending } = useQuery(sessionQuery())

  const session = data ?? null
  const { setTenantPrimary } = useTheme()

  // The workspace's primary colour reaches the tokens through the ThemeProvider (themes.md §Tenant branding).
  const primary = session?.tenant.branding.primary ?? null
  useEffect(() => {
    setTenantPrimary(primary)
  }, [primary, setTenantPrimary])
  const status: SessionStatus = isPending ? 'loading' : session ? 'authenticated' : 'anonymous'

  const value: SessionContextValue = {
    status,
    session,
    permissions: session?.permissions ?? [],
    refresh: async () => {
      await reloadSession(queryClient)
    },
    signOut: async () => {
      const workspace = session?.tenant.slug
      try {
        await logout()
      } finally {
        clearSession(queryClient)
        toast.success(copy.auth.signedOut)
        if (workspace === undefined) {
          await navigate({ to: '/' })
        } else {
          await navigate({ to: '/$workspace/login', params: { workspace }, search: {} })
        }
      }
    },
  }

  return <SessionContext value={value}>{children}</SessionContext>
}
