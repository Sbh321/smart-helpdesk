# Duplicate Integration Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans. The owner explicitly requested implementing all Milestone 2 features before the full test pass, so acceptance checks and completion claims are deferred. The owner commits; agents do not.

**Goal:** Persist and explain tenant-safe duplicate suggestions during Ticket creation, with preview, dismiss and mark-duplicate actions in the API and SPA.

**Architecture:** Automation loads up to 50 recent same-tenant candidates by PostgreSQL trigram rank and calls the `DuplicateStrategy` contract. Tickets owns suggestion persistence and the guarded close-as-duplicate action; the SPA consumes stored results rather than re-scoring them.

**Tech Stack:** Laravel 13, PostgreSQL 18 `pg_trgm`, React 19, existing Jaccard baseline.

**Spec:** `roadmap/03-week-2-product.md` M2-10; `docs/05-algorithms/duplicate-detection.md`.

## Global constraints

- The algorithm baseline stays unchanged and callers use `DuplicateStrategy`, not `JaccardDuplicates`.
- Candidate selection is tenant-scoped, limited to 30 days, excludes closed tickets and is capped at 50.
- New suggestion rows have `tenant_id`, composite ticket FKs, model/factory/isolation registration and change capture.
- Every new API route has permission middleware and JsonResource shapes.
- Full tests, benchmark and completion status wait for the owner’s Week 2 gate.

## Review focus

- A cross-tenant candidate must never be returned or stored.
- A ticket must not suggest or mark itself.
- A target that is itself a duplicate must be rejected.
- Retrying create-time suggestion storage must not duplicate rows.
- Dismissed suggestions must stay dismissed on a repeated score.

## Slices

### 1. Persistence and candidate selection

- [x] Add `ticket_duplicate_suggestions` migration, model, factory and registries.
- [x] Bind `DuplicateStrategy` from configuration and add a candidate query using `similarity(title, ?)` with a 30-day window, `status <> closed`, and deterministic tie-breaker.

### 2. Actions and API

- [x] Persist suggestions after Ticket create, preserving existing decisions on retry.
- [x] Add preview, list, dismiss and mark-duplicate endpoints with resources and permission checks.

### 3. SPA

- [x] Add debounced create preview, stored Duplicates tab and guarded mark/dismiss dialog.
- [ ] Add Settings → Automation → Duplicates threshold form using the M2-01 Settings service.

### 4. Deferred gate

- [x] Regenerate OpenAPI/types and verify no drift at the generation step (CI drift gate deferred).
- [ ] Run contract worked-example, tenant-isolation, 5,000-row preview speed, API, browser and axe checks after all Week 2 implementation is in place.
- [ ] Update algorithm worked example and M2-10 Done evidence only after those checks pass.
