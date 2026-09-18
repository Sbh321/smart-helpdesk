import { createContext, useContext } from 'react'
import { hasPermission } from './can'
import type { MaybeSession } from './session'

export type SessionStatus = 'loading' | 'authenticated' | 'anonymous'

export interface SessionContextValue {
  status: SessionStatus
  /** `null` while anonymous or still loading. */
  session: MaybeSession
  permissions: readonly string[]
  /** Re-reads `GET /v1/me`. */
  refresh: () => Promise<void>
  /** `POST /v1/auth/logout`, then back to the workspace's sign-in page. */
  signOut: () => Promise<void>
}

export const SessionContext = createContext<SessionContextValue | null>(null)

/** The only consumer API for the session (docs/03-architecture/frontend.md §State by kind). */
export function useSession(): SessionContextValue {
  const value = useContext(SessionContext)
  if (!value) {
    throw new Error('useSession must be used inside <SessionProvider>')
  }
  return value
}

/**
 * Permission gate for UI. Permissions, never roles (CLAUDE.md). The list is empty until roles land
 * (roadmap M1-09), so every call denies until then — including while the session is still loading.
 */
export function useCan(permission: string): boolean {
  const { permissions } = useSession()
  return hasPermission(permissions, permission)
}
