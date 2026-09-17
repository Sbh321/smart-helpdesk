# ADR-0005 PostgreSQL 18 as the only database

**Status:** Accepted (2026-09-17)

## Context

PostgreSQL 18.6 is current (19 is beta). We need JSONB settings, full-text and trigram search, row-level security for tenant defence in depth, generated columns, partial indexes and native UUID. Research: [01-research/multitenancy-options.md](../01-research/multitenancy-options.md), [search-options.md](../01-research/search-options.md), infra research.

## Decision

- **PostgreSQL 18.x** (`postgres:18-trixie` image; Debian for collation determinism across dev, on-prem and cloud). Single database, single `public` schema.
- Use: native `uuid` columns (ADR-0013); `timestamptz` everywhere in UTC; JSONB for tenant settings, explanations, metadata (with size limits and schema validation in PHP); **stored** generated `tsvector` column with GIN (PG18 virtual generated columns cannot be indexed); `pg_trgm` GIN indexes for typeahead and duplicate pre-filter; partial indexes for hot subsets (open tickets, running timers); check constraints for enums and ranges; composite unique indexes that include `tenant_id`; `SELECT … FOR UPDATE` on per-tenant counter rows for gapless ticket numbers; RLS policies per tenant table (ADR-0006).
- Three database roles: `helpdesk_owner` (migrations, owns objects), `helpdesk_app` (runtime; no ownership, no `BYPASSRLS`) and `helpdesk_backup` (read-only with `BYPASSRLS`, used only by dump jobs).
- No PgBouncer in the MVP; no exotic features (partitioning, logical replication, pgvector) until measured need.

## Alternatives considered

MySQL/MariaDB (no RLS, weaker FTS, JSON less capable); SQLite (dev only, no RLS/FTS parity — rejected even for tests to avoid dialect drift); separate search engine (ADR-0011).

## Consequences

Tests run against PostgreSQL in CI (service container); migrations use `DB::statement` for RLS, extensions and generated columns; one database to back up on-prem.

## Migration / future considerations

pgvector for semantic duplicate detection in V1 (one image swap to `pgvector/pgvector:pg18`); partitioning of `ticket_events` and `audit_logs` by month at scale; PgBouncer with `SET LOCAL` request transactions if pooling is needed; dedicated databases for large tenants (ADR-0006).
