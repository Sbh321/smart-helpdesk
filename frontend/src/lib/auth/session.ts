import type { components } from '@/lib/api/schema'

/** The `/v1/me` payload: the signed-in user, their tenant and their flat permission list (docs/07-api/authentication.md). */
export type Session = components['schemas']['MeResource']
export type SessionUser = Session['user']
export type SessionTenant = Session['tenant']

/** `null` means "asked and nobody is signed in"; `undefined` means "not asked yet". */
export type MaybeSession = Session | null
