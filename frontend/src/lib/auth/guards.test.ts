import { describe, expect, it } from 'vitest'
import {
  afterSignInHref,
  guardAuthRoute,
  guardWorkspaceRoute,
  isWorkspaceSlug,
  sanitiseRedirect,
  withWorkspace,
  workspaceHref,
  workspaceOfPath,
} from './guards'
import type { Session } from './session'

function sessionIn(slug: string): Session {
  return { tenant: { slug } } as unknown as Session
}

describe('isWorkspaceSlug', () => {
  it('accepts the slug shape the API issues', () => {
    expect(isWorkspaceSlug('acme')).toBe(true)
    expect(isWorkspaceSlug('acme-support')).toBe(true)
    expect(isWorkspaceSlug('a1')).toBe(true)
  })

  it('rejects anything else, so a stray path segment 404s instead of guessing', () => {
    for (const value of ['', 'Acme', 'acme_support', '-acme', 'acme-', 'acme--x', 'a'.repeat(64)]) {
      expect(isWorkspaceSlug(value)).toBe(false)
    }
  })
})

describe('sanitiseRedirect', () => {
  it('keeps same-origin paths', () => {
    expect(sanitiseRedirect('/acme/tickets?status=open')).toBe('/acme/tickets?status=open')
    expect(sanitiseRedirect('/')).toBe('/')
  })

  it('drops anything that could leave the site', () => {
    expect(sanitiseRedirect('//evil.test/phish')).toBeUndefined()
    expect(sanitiseRedirect('/\\evil.test')).toBeUndefined()
    expect(sanitiseRedirect('https://evil.test')).toBeUndefined()
    expect(sanitiseRedirect('javascript:alert(1)')).toBeUndefined()
    expect(sanitiseRedirect('acme/tickets')).toBeUndefined()
  })

  it('drops non-strings and empty values', () => {
    expect(sanitiseRedirect(undefined)).toBeUndefined()
    expect(sanitiseRedirect(null)).toBeUndefined()
    expect(sanitiseRedirect(42)).toBeUndefined()
    expect(sanitiseRedirect('')).toBeUndefined()
  })
})

describe('workspaceOfPath / withWorkspace', () => {
  it('reads the first segment', () => {
    expect(workspaceOfPath('/acme/tickets')).toBe('acme')
    expect(workspaceOfPath('/acme')).toBe('acme')
    expect(workspaceOfPath('/')).toBeUndefined()
    expect(workspaceOfPath('/acme?x=1')).toBe('acme')
  })

  it('swaps the first segment and keeps the rest', () => {
    expect(withWorkspace('/acme/tickets?status=open', 'globex')).toBe('/globex/tickets?status=open')
    expect(withWorkspace('/acme', 'globex')).toBe('/globex')
    expect(withWorkspace('/', 'globex')).toBe('/globex')
  })
})

describe('guardWorkspaceRoute', () => {
  it('sends an anonymous visitor to the workspace sign-in page and remembers where they were going', () => {
    expect(guardWorkspaceRoute({ session: null, workspace: 'acme', href: '/acme/tickets?page=2' })).toEqual({
      kind: 'sign-in',
      workspace: 'acme',
      redirect: '/acme/tickets?page=2',
    })
  })

  it('never carries an off-site redirect into the sign-in page', () => {
    expect(guardWorkspaceRoute({ session: null, workspace: 'acme', href: '//evil.test/phish' })).toEqual({
      kind: 'sign-in',
      workspace: 'acme',
      redirect: '/acme',
    })
  })

  it('lets a session through its own workspace', () => {
    expect(
      guardWorkspaceRoute({ session: sessionIn('acme'), workspace: 'acme', href: '/acme/tickets' }),
    ).toEqual({ kind: 'allow' })
  })

  it('rewrites the URL when it names another workspace than the session (ADR-0021)', () => {
    expect(
      guardWorkspaceRoute({ session: sessionIn('acme'), workspace: 'globex', href: '/globex/tickets' }),
    ).toEqual({ kind: 'switch-workspace', workspace: 'acme', href: '/acme/tickets' })
  })
})

describe('afterSignInHref', () => {
  const session = sessionIn('acme')

  it('returns to the requested page inside the signed-in workspace', () => {
    expect(afterSignInHref(session, '/acme/tickets?page=2')).toBe('/acme/tickets?page=2')
  })

  it('falls back to the workspace home when nothing was requested', () => {
    expect(afterSignInHref(session, undefined)).toBe('/acme')
    expect(workspaceHref(session)).toBe('/acme')
  })

  it('drops a request meant for another workspace instead of rewriting it', () => {
    expect(afterSignInHref(session, '/globex/tickets')).toBe('/acme')
  })

  it('drops an off-site request', () => {
    expect(afterSignInHref(session, 'https://evil.test')).toBe('/acme')
    expect(afterSignInHref(session, '//evil.test')).toBe('/acme')
  })
})

describe('guardAuthRoute', () => {
  it('lets an anonymous visitor see the sign-in page', () => {
    expect(guardAuthRoute({ session: null, workspace: 'acme', redirect: undefined })).toEqual({
      kind: 'allow',
    })
  })

  it('sends a signed-in visitor on to where they were heading', () => {
    expect(
      guardAuthRoute({ session: sessionIn('acme'), workspace: 'acme', redirect: '/acme/tickets' }),
    ).toEqual({ kind: 'signed-in', href: '/acme/tickets' })
  })

  it('sends a signed-in visitor of another workspace to their own', () => {
    expect(guardAuthRoute({ session: sessionIn('acme'), workspace: 'globex', redirect: undefined })).toEqual({
      kind: 'signed-in',
      href: '/acme',
    })
  })
})
