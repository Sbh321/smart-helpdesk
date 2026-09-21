# Media Library and Attachments Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for the slices below. The owner explicitly requested implementation-first for all of Milestone 2, so test execution and completion claims are deferred to the Week 2 gate; this overrides the skill's per-slice test/commit cadence. The owner commits, not the coding agent.

**Goal:** Deliver tenant-safe direct uploads, attachment links, a searchable Media library and lifecycle cleanup.

**Architecture:** The Media module owns object keys, metadata, quota and signed URLs. Tickets and comments hold no storage keys; they call the Media attachment action to create typed `mediables` links. Every lookup remains tenant-scoped and every link validates its target in the same tenant.

**Tech Stack:** Laravel 13, PostgreSQL 18, S3-compatible RustFS, React 19, TanStack Query.

**Spec:** `roadmap/03-week-2-product.md` M2-08; `docs/04-domain/media.md`; `docs/03-architecture/storage.md`.

## Global constraints

- Every Media table has an immutable `tenant_id`, registry entry and isolation inventory entry.
- Every reportable table receives change capture; polymorphic links have a UUID key for the existing trigger.
- Maximum file size is 25 MiB; permit only the MIME list in `storage.md`.
- Use permission middleware on every route and generated object keys only.
- No git commit or push by agents; do not run the full test pass until all Week 2 features are implemented.

## Review focus

- Concurrent upload intents must not reserve beyond quota.
- A presigned upload with mismatched bytes must fail completion and not consume quota.
- Cross-tenant folder, item or attachment ids must be 404.
- An item still linked to a Ticket must not be purged.
- Cleanup must release used bytes exactly once on retry.

## Slices

### 1. Schema and models

- [x] Add `media_folders`, `media_items`, `mediables`, quota columns, indexes and constraints in `backend/app/Modules/Media/Database/Migrations/2026_09_20_090000_create_media_tables.php`.
- [x] Register tables in `TenantTables`, `ReportableTables` and `TenantModelInventory`; create `MediaFolder`, `MediaItem`, `Mediable` and ForTenant factories.
- [ ] At the Week 2 gate, run migrations and tenancy/change-capture/isolation tests on `helpdesk_test`.

### 2. Upload lifecycle and library API

- [ ] Add intent/complete actions with quota row locks, S3 presign, HEAD/read-back MIME and checksum verification, and once-only ready transition.
- [x] Add tenant-scoped list, download, variants, folder, rename/move/tag, trash/restore/purge and usage routes with JSON resources; acceptance checks remain deferred.
- [x] Add a scheduled `media:cleanup` command for pending items older than one hour and trash older than 30 days; acceptance checks remain deferred.
- [ ] At the Week 2 gate, run API, quota, retry and storage-fake tests.

### 3. Variants and attachments

- [x] Add the researched `intervention/image` 4.3 dependency and a tenant-aware job generating WebP thumb and preview keys; acceptance checks remain deferred.
- [x] Add `AttachMedia` for Ticket and Comment subjects, enforce 50/10 limits and prevent purging linked items; acceptance checks remain deferred.
- [ ] At the Week 2 gate, run variant, attachment and isolation tests.

### 4. SPA integration

- [x] Build multi-file uploader, progress/retry, library picker and attachment panels on Ticket create/detail and Comment composer; acceptance checks remain deferred.
- [x] Build Settings → Media library folder/list/search/tag/move/trash and quota controls; put all copy in `src/copy/en.ts`; acceptance checks remain deferred.
- [ ] Regenerate OpenAPI and typed client after API changes; at the Week 2 gate run lint, typecheck, unit/browser/axe suites and the live upload path.

### 5. Closeout

- [ ] Update `docs/04-domain/media.md`, `docs/03-architecture/storage.md`, task evidence and milestones only after acceptance checks pass.
