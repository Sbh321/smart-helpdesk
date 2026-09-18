import { z } from 'zod'
import { copy } from '@/copy/en'

const { validation } = copy.auth

/** Mirrors the API's own rules (docs/07-api/authentication.md); the server stays the authority. */
export const emailField = z
  .string()
  .trim()
  .min(1, validation.emailRequired)
  .max(254, validation.emailInvalid)
  .pipe(z.email(validation.emailInvalid))

export const passwordField = z.string().min(1, validation.passwordRequired)

export const newPasswordField = z.string().min(12, validation.passwordTooShort)

const passwordsMatch = {
  message: validation.passwordMismatch,
  path: ['password_confirmation'],
}

export const loginSchema = z.object({
  email: emailField,
  password: passwordField,
})
export type LoginValues = z.infer<typeof loginSchema>

export const forgotPasswordSchema = z.object({ email: emailField })
export type ForgotPasswordValues = z.infer<typeof forgotPasswordSchema>

export const acceptInvitationSchema = z
  .object({
    name: z.string().trim().max(120),
    password: newPasswordField,
    password_confirmation: z.string().min(1, validation.passwordRequired),
  })
  .refine((value) => value.password === value.password_confirmation, passwordsMatch)
export type AcceptInvitationValues = z.infer<typeof acceptInvitationSchema>

export const resetPasswordSchema = z
  .object({
    email: emailField,
    password: newPasswordField,
    password_confirmation: z.string().min(1, validation.passwordRequired),
  })
  .refine((value) => value.password === value.password_confirmation, passwordsMatch)
export type ResetPasswordValues = z.infer<typeof resetPasswordSchema>

/** The workspace segment as typed on the entry page. */
export const workspaceSchema = z.object({
  workspace: z
    .string()
    .trim()
    .min(1, copy.workspaceEntry.required)
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, copy.workspaceEntry.invalid),
})
export type WorkspaceValues = z.infer<typeof workspaceSchema>
