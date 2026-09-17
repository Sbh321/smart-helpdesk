# ADR-0018 Mail: bundled self-hosted mail server with relay option; inbound email-to-ticket

**Status:** Accepted (2026-09-17). Moves email-to-ticket from the V1 backlog into the MVP as a Should-have; the outbound mail server is a Must-have.

## Context

The owner asked for a mail server "for handling mails". A helpdesk lives on email: agents and contacts are notified by email, contacts reply by email, and many tickets arrive by email. On-prem installs cannot depend on a SaaS mail provider, but cloud VPS providers often block outbound port 25. Research: [mail-and-media-options.md](../01-research/mail-and-media-options.md).

## Decision

1. **Stalwart Mail Server** (0.16.x, AGPL-3.0/SELv2) is bundled as the `mail` Compose service (profile `mail`, on by default in on-prem and demo installs): SMTP submission (587, app account) and inbound SMTP (25), IMAP for the app's inbound mailbox, DKIM signing per sending domain, SPF/DMARC/ARC verification, web admin gated to platform admins.
2. **Outbound**: Laravel's `smtp` mailer points at `mail:587`. Stalwart delivers directly or, when `MAIL_RELAY_HOST` is set, relays everything through an external SMTP provider (Postmark, SES, Resend, any SMTP). Cloud SaaS may skip the container entirely and use a provider mailer; the application code is identical.
3. **Inbound (Should-have)**: tenant support addresses `support+<tenant-slug>@<platform-domain>` (or a verified tenant domain in V1) and notification `Reply-To: ticket+<ticket-uuid>@<platform-domain>`. A `Mail` module fetches the `inbound` mailbox by IMAP every minute (`webklex/laravel-imap`), threads by plus-address first and `In-Reply-To`/`References` second, strips quotes and signatures with our own `ReplyParser`, creates a public comment or a new ticket (contact found or created), stores attachments as media items, and records every message in `inbound_emails` with its outcome (comment, ticket, rejected, bounced). Auto-replies and bounces are detected by headers and ignored.
4. **Deliverability UI**: Settings → Email shows the DNS records to create (MX, SPF, DKIM, DMARC) and their check status (Could-have).

## Alternatives considered

docker-mailserver (mature, heavier, no JMAP; documented alternative), Postal (transactional only, extra database), provider-only mail (rejected for on-prem, kept as relay), MTA hooks push instead of IMAP polling (V1), Symfony DKIM signing in PHP (keys in one place is better).

## Consequences

One more container (Rust, small); operators need DNS and a PTR record for direct delivery, or a relay; the Mail module adds threading, parsing and tests (about two days including the inbound path). AGPL applies to Stalwart as a separate, unmodified service (mere aggregation), which the licence question in [08-decisions-open-questions.md](../../roadmap/08-decisions-open-questions.md) already covers.

## Migration / future considerations

Per-tenant verified domains with DKIM keys; MTA hooks for instant inbound; JMAP; agent mailboxes; email templates editor; bounce/complaint handling loop.
