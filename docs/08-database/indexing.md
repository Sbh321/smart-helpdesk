# Indexing

Every index below exists for a named query. Anything not listed is not indexed until a measurement (E5 in [05-algorithms/evaluation-methodology.md](../05-algorithms/evaluation-methodology.md)) shows a need. Naming follows [overview.md](overview.md).

## Rules

1. Every application-plane index that serves a list query starts with `tenant_id` so the planner narrows to one tenant first.
2. Every foreign key gets a btree index (PostgreSQL does not create them automatically) unless it is the leading column of another index.
3. Unique constraints on tenant data are composite with `tenant_id`.
4. Partial indexes for hot subsets that are small relative to the table.
5. Full-text and trigram indexes are GIN.
6. No JSONB indexes in the MVP (no business query filters on JSONB).

## Tickets

| Index | Definition | Serves |
|---|---|---|
| `tickets_tenant_number_key` | UNIQUE `(tenant_id, number)` | number lookup, invariant |
| `tickets_tenant_status_priority_idx` | `(tenant_id, status, priority_score DESC, created_at DESC, id)` | default list sort with status filter |
| `tickets_tenant_created_idx` | `(tenant_id, created_at DESC, id)` | date-range filters, created-over-time analytics |
| `tickets_tenant_updated_idx` | `(tenant_id, updated_at DESC)` | `filter[updated_since]` pollers |
| `tickets_tenant_agent_status_idx` | `(tenant_id, assigned_agent_id, status)` | "my tickets", agent workload, load computation |
| `tickets_tenant_team_status_idx` | `(tenant_id, team_id, status)` | team queues |
| `tickets_tenant_contact_idx` | `(tenant_id, contact_id, created_at DESC)` | contact page |
| `tickets_tenant_org_idx` | `(tenant_id, organization_id)` | organisation filter |
| `tickets_tenant_category_idx` | `(tenant_id, category_id)` | category filter and analytics |
| `tickets_open_pidx` | `(tenant_id, priority_score DESC, id) WHERE status NOT IN ('resolved','closed')` | active queue, hourly re-evaluation pass |
| `tickets_unassigned_pidx` | `(tenant_id, created_at) WHERE assigned_agent_id IS NULL AND status IN ('open')` | unassigned KPI and manager view |
| `tickets_default_sort_idx` | `(tenant_id, priority_score DESC, created_at DESC, id)` | the default list order across all statuses (added in M1-17; the partial `tickets_open_pidx` only covers active tickets) |
| `tickets_search_gin` | GIN `(search_vector)` | `search=` FTS, duplicate candidate FTS branch |
| `tickets_title_trgm_gin` | GIN `(title gin_trgm_ops)` | short-term `ILIKE`, duplicate candidate `similarity()` |
| `tickets_duplicate_of_idx` | `(duplicate_of_id) WHERE duplicate_of_id IS NOT NULL` | "duplicates of this ticket" |
| `tickets_resolved_at_idx` | `(tenant_id, resolved_at) WHERE resolved_at IS NOT NULL` | resolved-today KPI, auto-close sweep |

### Reference list query

```sql
SELECT t.* FROM tickets t
WHERE t.tenant_id = $1
  AND t.status = ANY($2)
  AND t.assigned_agent_id IS NULL
  AND t.priority_level = ANY($3)
ORDER BY t.priority_score DESC, t.created_at DESC, t.id
LIMIT 25 OFFSET 25;
```

Expected plan at 100 k tickets/tenant: Index Scan on `tickets_tenant_status_priority_idx` (Bitmap Heap Scan with BitmapOr when several statuses), filter on `assigned_agent_id`/`priority_level`, no Sort node, < 10 ms. With `search=`: Bitmap Index Scan on `tickets_search_gin` BitmapAnd'ed with the tenant btree, then Sort by `ts_rank_cd` on ≤ a few thousand rows. `filter[sla_state]` adds a semi-join on `ticket_sla_timers` using its partial index. `EXPLAIN (ANALYZE, BUFFERS)` output is captured in the E5 results; a test asserts `Seq Scan` on `tickets` does not appear for the default list query.

## Ticket children

| Table | Index | Serves |
|---|---|---|
| ticket_comments | `(tenant_id, ticket_id, created_at)` | thread |
| ticket_comments | `(tenant_id, ticket_id) WHERE visibility = 'public'` | first-response detection, portal (V1) |
| media_items | `(tenant_id, folder_id, created_at DESC, id)`; GIN trigram `(name)`; UNIQUE `(storage_key)`; `(created_at) WHERE state = 'pending'`; `(trashed_at) WHERE state = 'trashed'` | library browsing, search, cleanup |
| mediables | `(tenant_id, mediable_type, mediable_id)` | attachments of a ticket/comment |
| business_calendars / calendar_holidays | partial UNIQUE default; UNIQUE `(calendar_id, date)` | SLA arithmetic |
| agent_shifts | `(tenant_id, agent_profile_id, weekday)`; `(tenant_id, date)` | eligibility check |
| entity_changes | UNIQUE `(entity_type, entity_id, version)`; `(tenant_id, entity_type, entity_id, occurred_at DESC)`; `(tenant_id, occurred_at DESC)`; `(tenant_id, actor_id, occurred_at DESC)` | history timelines, as-of replay, activity reports |
| report_ticket_intervals | UNIQUE `(tenant_id, ticket_id, seq)`; GiST `(tenant_id, tstzrange(starts_at, ends_at))` (btree_gist); `(tenant_id, assigned_agent_id, starts_at)`; partial `(tenant_id, status) WHERE ends_at IS NULL` | backlog at an instant, workload over time, current state |
| report_ticket_facts | `(tenant_id, created_at)`; `(tenant_id, resolved_at)`; `(tenant_id, team_id, created_at)`; `(tenant_id, assigned_agent_id, resolved_at)`; `(tenant_id, organization_id, created_at)` | report filters |
| report_daily_snapshots | UNIQUE `(tenant_id, day, dimension, dimension_key)` | trends |
| inbound_emails | UNIQUE `(message_id)`; `(tenant_id, received_at DESC)`; `(state) WHERE state IN ('unrouted','failed')` | idempotency, inbound log |
| ticket_events | `(tenant_id, ticket_id, created_at DESC, id)` | history cursor feed |
| ticket_events | `(tenant_id, created_at) WHERE type IN ('sla_breached','sla_warning')` | analytics breach counts |
| ticket_assignments | `(tenant_id, ticket_id, created_at DESC)`; `(tenant_id, agent_profile_id, created_at DESC)` | history, last-assignment lookup |
| ticket_duplicate_suggestions | UNIQUE `(ticket_id, candidate_ticket_id)`; `(tenant_id, ticket_id) WHERE decision = 'pending'` | suggestions panel, `has_duplicate_suggestion` filter |
| taggables | PK `(tag_id, taggable_type, taggable_id)`; `(tenant_id, taggable_type, taggable_id)` | tag filter (`EXISTS`), tags of a ticket |

## SLA

| Index | Definition | Serves |
|---|---|---|
| `ticket_sla_timers_tenant_ticket_kind_cycle_key` | UNIQUE `(tenant_id, ticket_id, kind, cycle)` | tenant-safe invariant, lookup |
| `ticket_sla_timers_due_pidx` | `(due_at) WHERE state IN ('running','warning')` | breach sweep range scan (global across tenants) |
| `ticket_sla_timers_warning_pidx` | `(warning_at) WHERE state = 'running'` | warning sweep |
| `ticket_sla_timers_tenant_state_idx` | `(tenant_id, kind, state)` | `filter[sla_state]`, compliance analytics |
| `ticket_sla_timers_tenant_met_idx` | `(tenant_id, kind, met_at) WHERE met_at IS NOT NULL` | compliance over a period |
| `sla_events` | `(tenant_id, ticket_id, created_at)`; `(timer_id, type)` | timeline, idempotency checks |
| `sla_targets` | UNIQUE `(policy_id, priority_level)` | policy lookup |
| `sla_policies` | UNIQUE `(tenant_id, name)`; UNIQUE `(tenant_id) WHERE is_default` | one default per tenant |

The sweep runs as the app role with `app.current_tenant` unset per tenant iteration; it iterates tenants and sets the GUC, so the partial indexes above are combined with the RLS predicate on `tenant_id` (the planner uses the partial index and filters on tenant). At 10 000 running timers this is milliseconds per tenant.

## Contacts and organisations

| Index | Definition | Serves |
|---|---|---|
| `contacts_tenant_email_key` | UNIQUE `(tenant_id, lower(email))` | uniqueness, login-less lookup by API |
| `contacts_tenant_name_idx` | `(tenant_id, name)` | default sort |
| `contacts_name_trgm_gin` | GIN `(name gin_trgm_ops)` | typeahead |
| `contacts_email_trgm_gin` | GIN `(email gin_trgm_ops)` | typeahead |
| `contacts_tenant_org_idx` | `(tenant_id, organization_id)` | org filter |
| `contacts_active_pidx` | `(tenant_id, name) WHERE archived_at IS NULL` | default list excludes archived |
| `organizations_tenant_name_key` | UNIQUE `(tenant_id, name)` | |
| `tags_tenant_slug_key`, `tags_tenant_name_key` | UNIQUE | |
| `tags_name_trgm_gin` | GIN `(name gin_trgm_ops)` | tag picker |

## Identity, agents, tenancy

| Index | Definition | Serves |
|---|---|---|
| `users_tenant_email_key` | UNIQUE `(tenant_id, lower(email))` | login |
| `users_tenant_active_idx` | `(tenant_id, is_active)` | user list |
| `invitations_token_hash_key` | UNIQUE `(token_hash)` | accept |
| `agent_profiles_tenant_id_user_id_unique` | UNIQUE `(tenant_id, user_id)` | one profile per User in a tenant |
| `agent_profiles_tenant_avail_idx` | `(tenant_id, availability, active_ticket_count)` | eligibility scan |
| `agent_skills_tenant_skill_idx` | `(tenant_id, skill_id)` | skill-to-Agent joins |
| `team_members_tenant_agent_idx` | `(tenant_id, agent_profile_id)` | Agent-to-Team joins |
| `agent_shifts_tenant_agent_weekday_idx`, `agent_shifts_tenant_date_idx` | `(tenant_id, agent_profile_id, weekday)`; `(tenant_id, date)` | shift eligibility and exception lookup |
| `category_skill` | PK `(tenant_id, category_id, skill_id)` | Category required skills |
| `model_has_roles` | PK `(tenant_id, role_id, model_id, model_type)`; `(tenant_id, model_id)` | permission loading |
| `roles_tenant_name_guard_key` | UNIQUE `(tenant_id, name, guard_name)` with `NULLS NOT DISTINCT` | global vs tenant roles |
| `domains_domain_key` | UNIQUE `(domain)` | host resolution |
| `domains_primary_pidx` | UNIQUE `(tenant_id) WHERE is_primary` | |
| `tenants_slug_key` | UNIQUE `(slug)` | workspace lookup at login |
| `sessions_user_idx`, `sessions_last_activity_idx` | | GC |

## Notifications, integrations, audit, analytics

| Index | Definition | Serves |
|---|---|---|
| `notifications_unread_pidx` | `(tenant_id, notifiable_id, created_at DESC) WHERE read_at IS NULL` | bell count and unread-first feed |
| `notifications_feed_idx` | `(tenant_id, notifiable_id, created_at DESC, id)` | cursor feed |
| `notifications_key_key` | UNIQUE `(tenant_id, notifiable_id, notification_key)` | dedup on retries |
| `webhook_subscriptions_tenant_active_idx` | `(tenant_id) WHERE is_active` | fan-out lookup |
| `webhook_deliveries_sub_created_idx` | `(subscription_id, created_at DESC, id)` | delivery log cursor |
| `webhook_deliveries_retry_pidx` | `(next_attempt_at) WHERE state = 'failed'` | retry scheduler (V1 if jobs use `release` only) |
| `webhook_deliveries_prune_idx` | `(created_at)` | 30-day prune |
| `webhook_deliveries_event_idx` | `(tenant_id, event_id)` | "all deliveries of this event" |
| `idempotency_keys` | PK `(tenant_id, client_id, key)`; `(expires_at)` | replay, prune |
| `audit_logs_tenant_created_idx` | `(tenant_id, created_at DESC, id)` | viewer cursor feed |
| `audit_logs_subject_idx` | `(tenant_id, subject_type, subject_id)` | per-record audit trail |
| `audit_logs_actor_idx` | `(tenant_id, actor_id, created_at DESC)` | per-actor filter |
| `exports_user_idx` | `(tenant_id, requested_by_user_id, created_at DESC)` | my exports |
| `oauth_clients_tenant_idx` | `(tenant_id) WHERE revoked_at IS NULL` | client list, token issuance |

## Reporting queries

Reports read the `report_*` read models and `entity_changes` with the indexes listed above ([reporting.md](../04-domain/reporting.md)). The dashboard's live tiles read `tickets` by `(tenant_id, status)`, `(tenant_id, priority_level)`, `(tenant_id, category_id)`, `(tenant_id, created_at)` and `ticket_sla_timers` by `(tenant_id, kind, state)`; all covered above. Results are cached per tenant for 60 s (Valkey), so the aggregates run at most once a minute per tenant.

## Maintenance

- Autovacuum defaults are adequate; `ticket_events` and `webhook_deliveries` are insert-heavy and benefit from `autovacuum_vacuum_insert_threshold` (PG13+ default) — no tuning in the MVP.
- No scheduled `REINDEX`; UUID v7 keys keep btrees append-mostly.
- `pg_stat_statements` is enabled in the Compose PostgreSQL config so the E5 report can list the top queries.
- A Pest test runs `EXPLAIN (FORMAT JSON)` on the reference list query and the sweep query and fails on `Seq Scan` of `tickets`/`ticket_sla_timers`.
