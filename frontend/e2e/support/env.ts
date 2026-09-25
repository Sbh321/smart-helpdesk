import path from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Where the suite runs and who it signs in as. The defaults are the Compose development stack with the
 * demo dataset (roadmap/11-demo-dataset.md); every value can be overridden from the environment.
 */
export const appUrl = process.env.E2E_BASE_URL ?? 'https://app.shp.localhost'
const platformDomain = new URL(appUrl).hostname.replace(/^app\./, '')
/** The API host (ADR-0021): the SPA on `app` calls `api` with the session cookie. */
export const apiUrl = process.env.E2E_API_URL ?? `https://api.${platformDomain}`
/** The landing site on the apex host (M5-04). */
export const landingUrl = process.env.E2E_LANDING_URL ?? `https://${platformDomain}`
export const mailpitUrl = process.env.E2E_MAILPIT ?? `https://mail.${platformDomain}`
/** webhook-echo's port published on the host loopback (infra/compose/tools.yaml). */
export const echoUrl = process.env.E2E_WEBHOOK_ECHO ?? 'http://127.0.0.1:9100'
/** The same receiver as the API's worker sees it on the Compose network. */
export const echoInternalUrl = process.env.E2E_WEBHOOK_ECHO_INTERNAL ?? 'http://webhook-echo:9100'

export const password = process.env.E2E_PASSWORD ?? 'password'

export const users = {
  /** Tenant Owner of Acme: settings, users, integrations, audit. */
  meera: { workspace: 'acme', email: process.env.E2E_ADMIN_EMAIL ?? 'meera@acme.test' },
  /** Support Manager of Acme with an Agent profile (golden path). */
  priya: { workspace: 'acme', email: process.env.E2E_EMAIL ?? 'priya@acme.test' },
  /** Support Agent of Acme; signs in through the form in the theme spec. */
  chen: { workspace: 'acme', email: 'chen@acme.test' },
  /** Tenant Admin of Globex: the isolation negative. */
  sam: { workspace: 'globex', email: 'sam@globex.test' },
} as const

export type UserKey = keyof typeof users

const here = path.dirname(fileURLToPath(import.meta.url))
export const repoRoot = path.resolve(here, '../../..')

/** Signed-in browser state per user, written once per run by global-setup.ts (git-ignored). */
export function stateFor(user: UserKey): string {
  return path.resolve(here, '../.auth', `${user}.json`)
}

/** A short suffix that makes the data one run creates unique, so specs need no reset between runs. */
export function stamp(): string {
  return `${Date.now().toString(36)}${Math.floor(Math.random() * 1296)
    .toString(36)
    .padStart(2, '0')}`
}
