# Security testing

Maps every threat in [03-architecture/security.md](../03-architecture/security.md) to an automated test, a static check or a manual review step. Scheduled in M1 (route protection, isolation), M2 (uploads, permissions), M3 (webhooks, SSRF, headers, manual review) per [roadmap/05-testing-plan.md](../../roadmap/05-testing-plan.md).

## Automated tests

| Threat | Test | Location | Week |
|---|---|---|---|
| Authorisation bypass | Route-list test: every `/api` route has `auth` + `can:` or is in the public allow-list | `tests/Permissions/RouteProtectionTest` | M1 |
| Role escalation | Permission matrix (routes × roles × API scopes); last-owner removal refused; agent with `own` scope cannot update others' tickets | `tests/Permissions/MatrixTest`, `TicketPolicyTest` | M2 |
| Tenant leakage / IDOR | Isolation suite items 1–12 ([testing.md](testing.md)) | `tests/Isolation/*` | M1–M3 |
| Tenant spoofing | A's session with B's workspace in the SPA URL → redirected to A; tampered session tenant → 401; login with B's workspace and A's credentials → 401; unknown host → no route | `tests/Isolation/HostTest` | M1 |
| Brute force | 6th login attempt within a minute → 429 with `Retry-After`; lockout after 10 failures | `tests/Feature/Identity/LoginThrottleTest` | M1 |
| Invitation/reset token misuse | expired signed URL → 403; reused token → 403; token for another tenant → 404 | `tests/Feature/Identity/InvitationTest` | M1 |
| CSRF | POST without `X-XSRF-TOKEN` from a stateful origin → 419; bearer requests unaffected | `tests/Feature/Identity/CsrfTest` | M1 |
| OAuth token exposure | expired token → 401; revoked client → 401; scope missing → 403; token of tenant A never reads tenant B's resources (404) | `tests/Feature/Integrations/ClientCredentialsTest` | M3 |
| Webhook forgery | signature computed with wrong secret rejected by the bundled verifier; signature covers `timestamp.body` | `tests/Unit/Integrations/SignatureTest` | M3 |
| Webhook replay | timestamp older than 5 min rejected by verifier; `event_id` stable across retries | `SignatureTest`, `DeliveryRetryTest` | M3 |
| SSRF via webhook URL | `http://` in production, `127.0.0.1`, `10.0.0.0/8`, `169.254.169.254`, `localhost`, hostnames resolving to private ranges → 422 `invalid_webhook_url`; redirects not followed | `tests/Unit/Integrations/WebhookUrlValidatorTest` | M3 |
| Malicious uploads | denied MIME (`svg`, `html`, `exe`), over-size, MIME mismatch on complete (PNG extension with PHP bytes) → 422 and object deleted; download response has `Content-Disposition: attachment` | `tests/Feature/Tickets/AttachmentTest` | M2 |
| XSS | Markdown renderer strips `<script>`, `onerror`, `javascript:` links (allow-list test); React component test renders ticket description as text | `tests/Unit/Tickets/MarkdownSanitizerTest`, Vitest | M2 |
| SQL injection | FTS/trigram scopes with `'; DROP TABLE` and `\` inputs return normal results; Larastan flags string-concatenated raw queries | `tests/Feature/Tickets/SearchTest` | M2 |
| Rate limiting | API client exceeding per-client limit → 429; per-tenant upload limit | `tests/Feature/RateLimitTest` | M3 |
| Suspended tenant | all tenant routes 403 `tenant_suspended` | isolation item 12 | M1 |
| Audit trail | each audited action writes an `audit_logs` row with actor, ip, request id | `tests/Feature/Audit/*` | M2–M3 |
| Secrets in logs | log processor test: request bodies and `Authorization` headers never appear in log lines | `tests/Unit/Support/LogRedactionTest` | M2 |

## Static and dependency checks (CI)

| Check | Tool | Gate |
|---|---|---|
| PHP dependency vulnerabilities | `composer audit` | high/critical fail |
| JS dependency vulnerabilities | `pnpm audit --audit-level=high` | fail |
| Secret scanning | `gitleaks` (pre-commit `protect --staged`, CI full history on PR) | any finding fails |
| Static analysis | Larastan level 6; Biome `suspicious/*` rules | fail |
| Dependency review | GitHub dependency-review action on PRs | fail on high |
| Licence inventory | `just licences` | reviewed M3 |

## Manual review checklist (M3, half a day)

OWASP Top 10 walkthrough with checks specific to this application:

| Category | Concrete checks |
|---|---|
| A01 Broken access control | Sample five endpoints per module in the browser as Agent; try another tenant's UUID, another user's notification, an attachment download link after logout; confirm 404/401 |
| A02 Cryptographic failures | `APP_KEY` set and unique per environment; webhook and client secrets encrypted/hashed at rest (inspect DB); TLS only; cookies `Secure`, `HttpOnly`, `SameSite=Lax` (inspect DevTools) |
| A03 Injection | grep for `DB::raw`, `whereRaw`, `selectRaw` outside `Queries/` and migrations; every raw query uses bindings; Markdown sanitiser allow-list reviewed |
| A04 Insecure design | ticket number counter under lock; assignment under row lock; presigned URL TTLs 5 min; keys server-generated |
| A05 Security misconfiguration | `APP_DEBUG=false`, `TELESCOPE_ENABLED=false` in prod compose; Horizon/health/log-viewer gated to platform admins; storage console port not published; Valkey `requirepass`; PostgreSQL ports not published |
| A06 Vulnerable components | audits green; RustFS pinned; Dependabot enabled |
| A07 Identification failures | throttles verified with curl loop; session invalidated on password change; invitation tokens single-use |
| A08 Integrity failures | lockfiles committed; images pinned by digest in prod compose; webhook signature docs published |
| A09 Logging failures | JSON logs carry `request_id`, `tenant_id`, `user_id`; auth failures logged; no PII bodies |
| A10 SSRF | webhook URL validator; no other user-supplied URLs fetched server-side |

## Header verification

Run against the demo stack after M3 deployment:

```bash
curl -sI https://app.shp.localhost/acme | grep -iE 'strict-transport|content-security|x-content-type|x-frame|referrer-policy|permissions-policy'
curl -si https://api.shp.localhost/v1/me | grep -i 'set-cookie'   # expect Secure; HttpOnly; SameSite=Lax
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.shp.localhost/v1/tickets   # expect 401 (no session) not 419/500
for i in $(seq 1 7); do curl -s -o /dev/null -w '%{http_code} ' -X POST https://api.shp.localhost/v1/auth/login -H 'Content-Type: application/json' -d '{"email":"x@acme.test","password":"wrong"}'; done; echo   # expect 401×5 then 429
```

Expected CSP: `default-src 'self'; img-src 'self' data: <storage-endpoint>; connect-src 'self' <storage-endpoint> wss://<host>; frame-ancestors 'none'`.

## Webhook receiver verification snippet (published in API docs)

```php
$expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
abort_unless(hash_equals($expected, $signature) && abs(time() - (int) $timestamp) <= 300, 401);
```

The same logic is implemented in the bundled `tools/webhook-echo` container so the demo proves verification.

## Out of scope for the MVP

External penetration test; DAST scanners (ZAP) in CI; fuzzing; SAST beyond Larastan/Biome; malware scanning of uploads; MFA/SSO; IP allow-lists; field-level encryption. Listed in [roadmap/09-v1-backlog.md](../../roadmap/09-v1-backlog.md) §Security.
