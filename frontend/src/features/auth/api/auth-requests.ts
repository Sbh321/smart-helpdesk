import { api, ensureCsrfCookie, unwrapBody } from '@/lib/api/client'
import type { components } from '@/lib/api/schema'

/**
 * Pre-authentication requests (docs/07-api/authentication.md). Every one of them is unsafe, so the
 * Sanctum CSRF cookie is fetched first; the API client then echoes it as `X-XSRF-TOKEN`.
 *
 * The login and accept-invitation responses are deliberately ignored: Scramble currently infers their
 * 200 body as the literal `401`, and the SPA re-reads `GET /v1/me` anyway, which is the single source
 * of session truth.
 */

export type LoginInput = components['schemas']['LoginRequest']
export type AcceptInvitationInput = components['schemas']['AcceptInvitationRequest']
export type ForgotPasswordInput = components['schemas']['ForgotPasswordRequest']
export type ResetPasswordInput = components['schemas']['ResetPasswordRequest']

export async function login(body: LoginInput): Promise<void> {
  await ensureCsrfCookie()
  await unwrapBody(api().POST('/auth/login', { body }))
}

export async function logout(): Promise<void> {
  await ensureCsrfCookie()
  await unwrapBody(api().POST('/auth/logout', {}))
}

export async function acceptInvitation(token: string, body: AcceptInvitationInput): Promise<void> {
  await ensureCsrfCookie()
  await unwrapBody(api().POST('/auth/invitations/{token}/accept', { params: { path: { token } }, body }))
}

export async function requestPasswordReset(body: ForgotPasswordInput): Promise<void> {
  await ensureCsrfCookie()
  await unwrapBody(api().POST('/auth/password/forgot', { body }))
}

export async function resetPassword(body: ResetPasswordInput): Promise<void> {
  await ensureCsrfCookie()
  await unwrapBody(api().POST('/auth/password/reset', { body }))
}
