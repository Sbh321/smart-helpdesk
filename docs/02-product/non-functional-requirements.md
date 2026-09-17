# Non-functional requirements

Targets are split into **MVP-tested** (we measure them before the demo) and **architectural** (the design must not preclude them; not measured in the MVP). Measurement method is in [10-quality/performance-testing.md](../10-quality/performance-testing.md).

## Security

| ID | Requirement | Verification |
|---|---|---|
| NFR-SEC-01 | Tenant isolation: no cross-tenant read/write via any interface | Automated negative tests for every resource; RLS considered defence-in-depth for V1 ([ADR-0006](../adr/0006-multi-tenancy-model.md)) |
| NFR-SEC-02 | All endpoints require authentication except login, password set/reset, health, OAuth token, API docs | Route-list test asserting middleware |
| NFR-SEC-03 | Authorisation by permission on every mutating and reading endpoint | Permission matrix tests |
| NFR-SEC-04 | OWASP Top 10 controls: CSRF for cookie sessions, output encoding in React, parameterised queries via Eloquent, file upload validation, security headers | Checklist in [security-testing](../10-quality/security-testing.md) |
| NFR-SEC-05 | Secrets never in the repository; `.env.example` only | CI grep + review |
| NFR-SEC-06 | Login brute-force protection: 5 attempts per minute per email+IP | Feature test |
| NFR-SEC-07 | Webhook signatures HMAC-SHA256 with timestamp; receivers can reject replays older than 5 minutes | Unit test + documented verification snippet |
| NFR-SEC-08 | Object storage, Redis and PostgreSQL not exposed outside the Docker network in production | Compose review, Ansible firewall role |

## Performance (MVP-tested scale)

| ID | Requirement | Target |
|---|---|---|
| NFR-PERF-01 | Ticket list page (25 rows, filters, sort) | p95 < 300 ms API latency with 100 000 tickets in one tenant, 20 tenants |
| NFR-PERF-02 | Ticket creation including priority, duplicate detection (candidate set ≤ 200), assignment | p95 < 800 ms |
| NFR-PERF-03 | SLA evaluation pass | < 5 s for 10 000 running timers |
| NFR-PERF-04 | Dashboard aggregates | p95 < 1 s (cached 60 s) |
| NFR-PERF-05 | Concurrent users | 50 concurrent virtual users on a 2 vCPU / 4 GB VM without errors (k6 smoke test) |
| NFR-PERF-06 | Background work never runs in HTTP requests except duplicate preview and assignment (bounded) | Code review |

Architectural targets (not measured in the MVP): 1 M tickets per tenant with partitioned history tables; hundreds of concurrent agents; horizontal scaling of PHP-FPM and queue workers behind a load balancer; dedicated tenant databases.

## Reliability and operations

| ID | Requirement |
|---|---|
| NFR-OPS-01 | `docker compose up` brings up a working system with seeded demo data in under 10 minutes on a developer laptop |
| NFR-OPS-02 | Health endpoint reports DB, Redis, storage, queue and scheduler heartbeat |
| NFR-OPS-03 | Failed jobs are retained and retryable; webhook deliveries retried with backoff and dead-lettered after N attempts |
| NFR-OPS-04 | Daily database and storage backup on on-prem via a documented, tested restore procedure |
| NFR-OPS-05 | Structured JSON logs with request id and tenant id |
| NFR-OPS-06 | Zero-downtime is **not** an MVP target; maintenance-window deploys are acceptable |

## Usability and accessibility

| ID | Requirement |
|---|---|
| NFR-UX-01 | Light, dark and system themes; persisted per user; no flash of wrong theme |
| NFR-UX-02 | Keyboard operable: all interactive elements reachable and operable; visible focus ring |
| NFR-UX-03 | Colour contrast ≥ 4.5:1 for text in both themes (tokens checked) |
| NFR-UX-04 | Reduced-motion preference honoured |
| NFR-UX-05 | Every list has loading, empty and error states; every mutation has success/error feedback |
| NFR-UX-06 | Works at 1280 px and 1920 px; usable at 768 px (tablet) — mobile layout is V1 |

## Maintainability

| ID | Requirement |
|---|---|
| NFR-MNT-01 | Backend static analysis (Larastan level 6+) and style (Pint) clean in CI |
| NFR-MNT-02 | Frontend typecheck and lint clean in CI; no `any` in feature code |
| NFR-MNT-03 | Test coverage: algorithms 100 % line coverage; backend overall ≥ 70 % |
| NFR-MNT-04 | Module boundaries enforced by convention and reviewed; frontend import boundaries linted |
| NFR-MNT-05 | OpenAPI document generated in CI and diffed |

## Portability

| ID | Requirement |
|---|---|
| NFR-PORT-01 | No business logic depends on a specific cloud provider; storage, mail, realtime, database and cache are configuration |
| NFR-PORT-02 | Same container images run on workstation, on-prem server and cloud VM |
| NFR-PORT-03 | On-prem install runs with a single tenant on one hostname (single-host mode) |
