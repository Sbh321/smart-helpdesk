import type { MaybeSession, Session } from './session'

/**
 * Route-guard decisions as pure functions so they can be unit-tested without a router
 * (docs/03-architecture/frontend.md §Error handling, [ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)).
 *
 * The workspace segment in the URL is presentation only. It is never sent to the API as a tenant
 * selector; after `/v1/me` answers, it is compared with `tenant.slug` and corrected on mismatch.
 */

/** Search-param name that carries where to go after signing in. */
export const REDIRECT_PARAM = 'redirect'

/** Slug shape accepted in the URL; keep in step with the backend's tenant slug rule. */
const WORKSPACE_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

export function isWorkspaceSlug(value: string): boolean {
  return value.length > 0 && value.length <= 63 && WORKSPACE_PATTERN.test(value)
}

/**
 * Accepts only same-origin paths, so a crafted `?redirect=` cannot bounce the user off the site.
 * Protocol-relative (`//evil.test`), backslash and absolute URLs are rejected.
 */
export function sanitiseRedirect(value: unknown): string | undefined {
  if (typeof value !== 'string' || !value.startsWith('/')) {
    return undefined
  }
  if (value.startsWith('//') || value.startsWith('/\\')) {
    return undefined
  }
  return value
}

/** First path segment of an application path, or `undefined` for `/`. */
export function workspaceOfPath(path: string): string | undefined {
  const segment = path.split('?')[0]?.split('#')[0]?.split('/')[1]
  return segment !== undefined && segment.length > 0 ? segment : undefined
}

/** Replaces the workspace segment of a path, keeping the rest of the path, search and hash. */
export function withWorkspace(path: string, workspace: string): string {
  const current = workspaceOfPath(path)
  if (current === undefined) {
    return `/${workspace}`
  }
  return `/${workspace}${path.slice(current.length + 1)}`
}

export type WorkspaceGuard =
  | { kind: 'allow' }
  /** Nobody is signed in: go to the workspace's login page and come back to `redirect` afterwards. */
  | { kind: 'sign-in'; workspace: string; redirect: string }
  /** Signed in elsewhere: the URL names another workspace than the session, so correct the URL. */
  | { kind: 'switch-workspace'; workspace: string; href: string }

/** Guard for every authenticated route under `/{workspace}`. */
export function guardWorkspaceRoute(input: {
  session: MaybeSession
  workspace: string
  href: string
}): WorkspaceGuard {
  const { session, workspace, href } = input
  if (!session) {
    return { kind: 'sign-in', workspace, redirect: sanitiseRedirect(href) ?? `/${workspace}` }
  }
  if (session.tenant.slug !== workspace) {
    return {
      kind: 'switch-workspace',
      workspace: session.tenant.slug,
      href: withWorkspace(sanitiseRedirect(href) ?? `/${workspace}`, session.tenant.slug),
    }
  }
  return { kind: 'allow' }
}

/** Where the workspace home is for a session. */
export function workspaceHref(session: Session): string {
  return `/${session.tenant.slug}`
}

/**
 * Where to go once a sign-in succeeds: the requested path when it is safe and belongs to the session's
 * workspace, otherwise that workspace's home. A redirect into someone else's workspace is dropped
 * rather than rewritten, because it was meant for a different account.
 */
export function afterSignInHref(session: Session, requested: unknown): string {
  const target = sanitiseRedirect(requested)
  if (target === undefined) {
    return workspaceHref(session)
  }
  const wanted = workspaceOfPath(target)
  if (wanted === undefined || wanted !== session.tenant.slug) {
    return workspaceHref(session)
  }
  return target
}

/** Guard for an auth page: a signed-in user has no business on the login screen. */
export function guardAuthRoute(input: {
  session: MaybeSession
  workspace: string
  redirect: unknown
}): { kind: 'allow' } | { kind: 'signed-in'; href: string } {
  if (!input.session) {
    return { kind: 'allow' }
  }
  return { kind: 'signed-in', href: afterSignInHref(input.session, input.redirect) }
}
