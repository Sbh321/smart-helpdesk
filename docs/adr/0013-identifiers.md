# ADR-0013 Identifiers: UUID v7 primary keys, tenant-scoped ticket numbers

**Status:** Accepted (2026-09-17)

## Context

The brief asks for a ULID or UUID strategy. Laravel 13's `HasUuids` generates UUID v7 by default; PostgreSQL stores it natively in 16 bytes, versus ULID's 26-byte text. Both are time-ordered.

## Decision

- All primary keys are **UUID v7** via `HasUuids` in native `uuid` columns; foreign keys likewise. No auto-increment IDs are exposed anywhere.
- Human-facing identifiers: tickets get a **tenant-scoped sequential `number`** (unique `(tenant_id, number)`, allocated under `SELECT … FOR UPDATE` on `tenant_counters`); displayed as `#1042`. Other entities are addressed by UUID only.
- API paths use UUIDs; cross-tenant lookups return 404.

## Alternatives considered

ULID (larger text keys, no native type), UUID v4 (random inserts fragment indexes), integer IDs (enumeration and cross-tenant IDOR risk), `Str::orderedUuid()` (superseded).

## Consequences

Sortable keys with good index locality; no enumeration; readable ticket references for agents and customers.

## Migration / future considerations

Per-tenant prefixes (`ACME-1042`) are a display concern; PostgreSQL 18's native `uuidv7()` can generate IDs in SQL for bulk seeds.
