# ADR-0016 Testing: Pest, Vitest browser mode, Playwright with axe

**Status:** Accepted (2026-09-17)

## Context

Testing is academically assessed and operationally required (tenant isolation, permissions, algorithms). Research: backend and frontend ecosystem pages.

## Decision

- **Backend**: Pest 5 on PHPUnit 13 against PostgreSQL (never SQLite): unit tests for domain algorithms (100 % line coverage target), feature/API tests per endpoint, permission matrix tests, tenancy isolation suite, queue/scheduler tests with a frozen clock, architecture tests for module dependency rules. Larastan level 5→6 and Pint in CI.
- **Frontend**: Vitest 5 node tests for `lib/*` and schemas; Vitest browser mode with `vitest-browser-react` for components that depend on real layout/focus; MSW handlers derived from the OpenAPI schema.
- **End-to-end**: Playwright 1.63 against the Compose stack (golden path, tenant isolation, theme persistence) with `@axe-core/playwright` scans on key routes. No Playwright component testing, no Storybook, no visual regression in the MVP.
- **Experiments** are artisan commands with tests asserting reproducibility (same seed ⇒ same output hash).

## Alternatives considered

PHPUnit only (Pest is the Laravel default and reads better), jsdom + RTL (fakes focus/layout that Base UI needs), Cypress (slower, no axe parity), Dusk (Blade-oriented).

## Consequences

CI has three workflows (backend, frontend, e2e) with path filters; test counts and coverage are reported for the university testing chapter ([10-quality/testing.md](../10-quality/testing.md)).

## Migration / future considerations

k6 load tests as a nightly job; mutation testing (Infection) for algorithm modules; contract tests for webhooks.
