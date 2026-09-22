import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { apiUrl } from './handlers'
import { validationFailed } from './list'

/**
 * `GET/PATCH /v1/settings/email` (M3-18), shaped as the backend's `EmailSettingsResource`. Registered
 * before the generic settings handlers, as the backend registers its routes before `/settings/{section}`.
 */
type EmailSettings = components['schemas']['EmailSettingsResource']
type InboundEmail = components['schemas']['InboundEmailResource']

export const MAIL_DKIM_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey'

let senderName: string | null = null
let createContacts = true
let matchOrganisationDomain = true
let version = 0

export function resetMail(): void {
  senderName = null
  createContacts = true
  matchOrganisationDomain = true
  version = 0
  inboundDb.rows = inboundFixtures()
}

const TICKET_ID = '01990000-0000-7000-8000-000000000101'

function inboundRow(index: number, patch: Partial<InboundEmail>): InboundEmail {
  const minute = String(59 - index).padStart(2, '0')
  return {
    id: `01990000-0000-7000-8000-0000000e${String(index).padStart(4, '0')}`,
    state: 'comment',
    reason: null,
    route: 'plus_address',
    from: { address: 'asha@wayne-foods.test', name: 'Asha Rai' },
    to: [`ticket+${TICKET_ID}@shp.localhost`],
    cc: [],
    subject: 'Re: [#1001] Printer offline',
    message_id: `m${index}@wayne-foods.test`,
    ticket: { id: TICKET_ID, number: 1001, title: 'Printer offline' },
    comment_id: null,
    contact_id: null,
    attachments: [],
    sent_at: null,
    processed_at: `2026-09-22T09:${minute}:00Z`,
    ...patch,
  }
}

/** Five received messages, newest first: a comment, a new ticket, an auto-reply, a rejection, a comment. */
export function inboundFixtures(): InboundEmail[] {
  return [
    inboundRow(0, {
      attachments: [
        { name: 'screen.png', size: 70, media_id: '01990000-0000-7000-8000-0000000f0001', skipped: null },
      ],
    }),
    inboundRow(1, {
      state: 'ticket',
      route: 'intake',
      from: { address: 'ravi@umbrella.test', name: null },
      to: ['support+acme@shp.localhost'],
      subject: 'Invoice copy',
      ticket: { id: '01990000-0000-7000-8000-000000000109', number: 1009, title: 'Invoice copy' },
    }),
    inboundRow(2, { state: 'ignored', reason: 'auto_reply', subject: 'Automatic reply: Printer offline' }),
    inboundRow(3, {
      state: 'rejected',
      reason: 'sender_not_allowed',
      from: { address: 'mallory@evil.test', name: 'Mallory' },
      subject: 'Close this',
    }),
    inboundRow(4, { subject: '' }),
  ]
}

export const inboundDb: { rows: InboundEmail[] } = { rows: inboundFixtures() }

export function emailSettingsFixture(): EmailSettings {
  return {
    sender_name: senderName,
    default_sender_name: 'Acme Support',
    create_contacts: createContacts,
    match_organisation_domain: matchOrganisationDomain,
    from: { name: senderName ?? 'Acme Support', address: 'support+acme@shp.localhost' },
    platform_from: { name: 'Acme via Smart Helpdesk', address: 'no-reply@shp.localhost' },
    intake_address: 'support+acme@shp.localhost',
    reply_to_pattern: 'ticket+<ticket-id>@shp.localhost',
    mail_domain: 'shp.localhost',
    dns_records: [
      {
        type: 'MX',
        name: 'shp.localhost',
        value: '10 mail.shp.localhost',
        purpose: 'Receives replies and new requests for the intake addresses.',
        ready: true,
      },
      {
        type: 'TXT',
        name: 'shp.localhost',
        value: 'v=spf1 mx -all',
        purpose: 'SPF: the servers allowed to send for the domain.',
        ready: true,
      },
      {
        type: 'TXT',
        name: 'v1-rsa-20260921._domainkey.shp.localhost',
        value: `v=DKIM1; k=rsa; h=sha256; p=${MAIL_DKIM_KEY}`,
        purpose: 'DKIM: the public key that verifies the signature on every outgoing mail.',
        ready: true,
      },
      {
        type: 'TXT',
        name: '_dmarc.shp.localhost',
        value: 'v=DMARC1; p=none; rua=mailto:postmaster@shp.localhost',
        purpose: 'DMARC: what receivers do with mail that fails SPF and DKIM, and where they report.',
        ready: true,
      },
    ],
    version,
  }
}

export const mailHandlers = [
  http.get(apiUrl('/settings/email'), () => HttpResponse.json({ data: emailSettingsFixture() })),
  http.patch(apiUrl('/settings/email'), async ({ request }) => {
    const body = (await request.json()) as Record<string, unknown>
    for (const flag of ['create_contacts', 'match_organisation_domain'] as const) {
      if (flag in body && typeof body[flag] !== 'boolean')
        return validationFailed({ [flag]: [`The ${flag} field must be true or false.`] })
    }
    if ('sender_name' in body) {
      const name = typeof body.sender_name === 'string' ? body.sender_name.trim() : null
      if (name !== null && (name.length > 80 || /[\r\n<>"@\\]/.test(name)))
        return validationFailed({ sender_name: ['The sender name field format is invalid.'] })
      senderName = name === '' ? null : name
    }
    if (typeof body.create_contacts === 'boolean') createContacts = body.create_contacts
    if (typeof body.match_organisation_domain === 'boolean')
      matchOrganisationDomain = body.match_organisation_domain
    version += 1
    return HttpResponse.json({ data: emailSettingsFixture() })
  }),
  // Inbound log (M3-19): `IndexInboundEmailsRequest`, newest first, a cursor that is the offset of the next page.
  http.get(apiUrl('/inbound-emails'), ({ request }) => {
    const url = new URL(request.url)
    for (const key of ['page', 'search', 'sort'])
      if (url.searchParams.has(key)) return validationFailed({ [key]: ['Not supported.'] })
    const states = (url.searchParams.get('filter[state]') ?? '').split(',').filter((state) => state !== '')
    const rows = inboundDb.rows.filter((row) => states.length === 0 || states.includes(row.state))
    const perPage = Number(url.searchParams.get('per_page') ?? 25)
    const offset = Number(url.searchParams.get('cursor') ?? 0)
    return HttpResponse.json({
      data: rows.slice(offset, offset + perPage),
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        path: null,
        per_page: perPage,
        next_cursor: offset + perPage < rows.length ? String(offset + perPage) : null,
        prev_cursor: null,
      },
    })
  }),
]
