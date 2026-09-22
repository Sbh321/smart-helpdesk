# Pagination, filtering, sorting, search

Applies to every collection endpoint; the ticket list is the reference implementation (`Tickets\Queries\TicketListQuery`). The same parameter names are the SPA's route search params ([03-architecture/frontend.md](../03-architecture/frontend.md)), so a URL like `/tickets?filter[status]=open&sort=-priority_score` in the browser maps one-to-one onto the API call.

## Pagination

| Style | Endpoints | Params | Why |
|---|---|---|---|
| **Page-based** | tickets, contacts, organizations, users, agents, teams, skills, categories, tags, SLA policies, api clients, webhooks, exports | `page` (≥ 1), `per_page` (1–100, default 25) | Users paginate tables by page number and need `total` for the pager; PostgreSQL `OFFSET` with the composite indexes is fine at 100 k rows per tenant (measured in E5); simpler client code |
| **Cursor-based** | ticket history, notifications, audit logs, webhook deliveries | `cursor` (opaque), `per_page` | Append-only feeds where new rows arrive while paging; no `total` needed; Laravel `cursorPaginate` on `(created_at, id)` avoids skipping/duplicating rows |

Page response:

```json
{ "data": [...], "meta": { "current_page": 2, "per_page": 25, "total": 1387, "last_page": 56, "from": 26, "to": 50 }, "links": { "first": "…?page=1", "last": "…?page=56", "prev": "…?page=1", "next": "…?page=3" } }
```

Cursor response: `meta: { per_page, next_cursor, prev_cursor }`, `links: { next, prev }`. Out-of-range pages return an empty `data` array, not 404.

## Filtering

Syntax: `filter[field]=value` with comma-separated lists meaning **OR** within a field and **AND** across fields. Each endpoint publishes its allow-list; anything else is `422 validation_failed` with `errors["filter.xyz"]`.

Ticket list filters:

| Filter | Values | SQL |
|---|---|---|
| `filter[status]` | `open,assigned,in_progress,pending,resolved,closed`; alias `active` = all but resolved/closed | `status IN (...)` |
| `filter[priority]` | `P1,P2,P3,P4` (effective level) | `priority_level IN (...)` |
| `filter[assignee_id]` | UUIDs or `unassigned` or `me` (the signed-in user's Agent profile; nothing when there is none) | `assigned_agent_id IN (...)` / `IS NULL` |
| `filter[team_id]` | UUIDs or `none` | |
| `filter[category_id]` | UUIDs | |
| `filter[organization_id]` | UUIDs | |
| `filter[contact_id]` | UUIDs | |
| `filter[tag]` | tag slugs | `EXISTS (taggables …)` |
| `filter[sla_state]` | `running,warning,breached,paused,met` (resolution timer of the latest cycle) | correlated subquery on `ticket_sla_timers` |
| `filter[created_between]` | `YYYY-MM-DD,YYYY-MM-DD` (tenant timezone, inclusive) | `created_at >= … AND created_at < …+1d` |
| `filter[updated_since]` | ISO-8601 instant | for API pollers |
| `filter[impact]`, `filter[urgency]` | `1..4` lists | |
| `filter[has_duplicate_suggestion]` | `true` (a pending suggestion) | `EXISTS` |
| `filter[number]` | integer | exact |

Contacts: `filter[organization_id]`, `filter[tag]`, `filter[archived]`. Agents: `filter[team_id]`, `filter[skill_id]`, `filter[availability]`. Deliveries: `filter[state]`, `filter[event_type]`. Audit logs: `filter[action]` (exact or `user.*`), `filter[actor_type]`, `filter[actor_id]`, `filter[subject_type]`, `filter[subject_id]`, `filter[created_between]`.

## Search

`search=` is free text. Tickets: `websearch_to_tsquery('english', :q)` against the stored `search_vector`, plus `number = :q` when numeric, plus trigram `ILIKE` on `title` for short terms (< 3 tokens); results ordered by `ts_rank_cd` when no explicit `sort`. Contacts: trigram similarity on `name` and `email`. Max 200 characters; quotes and `-negation` supported by `websearch_to_tsquery`. Details: [ADR-0011](../adr/0011-search-architecture.md).

## Sorting

`sort=` accepts a comma list of allow-listed fields, `-` prefix for descending; every query ends with `id ASC` as a deterministic tie-breaker.

| Endpoint | Sortable fields | Default |
|---|---|---|
| tickets | `priority_score`, `priority_level`, `created_at`, `updated_at`, `number`, `status`, `sla_due_at` (resolution timer) | `-priority_score,-created_at` |
| contacts | `name`, `email`, `created_at`, `last_ticket_at` | `name` |
| agents | `name`, `active_ticket_count`, `availability` | `name` |
| audit logs, deliveries, history | fixed `-created_at` (cursor feeds) | |

Unknown sort fields → 422.

## Includes

`include=` loads relations allow-listed per endpoint (`contact,organization,category,tags` on tickets as built; `assignee`, `team`, `sla_timers` and `duplicate_suggestions` are not includes yet, so the list reads Agent and Team names from the directory and the SLA state only as a filter and a sort). Includes never change the base shape; they add nested objects. Collections cap includes to those that are eager-loadable in one query per relation (no N+1; asserted by a test with `preventLazyLoading`).

## Implementation mapping

```php
final class TicketListQuery
{
    public function __construct(private TicketListParams $params, private Tenant $tenant) {}
    public function paginate(): LengthAwarePaginator
    {
        return Ticket::query()                       // tenant global scope applies
            ->when($p->status, fn ($q) => $q->whereIn('status', $p->status))
            ->when($p->assignee === 'unassigned', fn ($q) => $q->whereNull('assigned_agent_id'))
            ->when($p->search, fn ($q) => $q->search($p->search))          // scope: FTS + number + trigram
            ->when($p->slaState, fn ($q) => $q->whereHas('resolutionTimer', fn ($t) => $t->whereIn('state', $p->slaState)))
            ->with($p->includes)
            ->orderByMany($p->sort)->orderBy('id')
            ->paginate($p->perPage);
    }
}
```

`TicketListParams` is a readonly DTO built by `IndexTicketsRequest`, which validates the allow-lists. The frontend's `ticketListSchema` (`defineListSchema`, Zod) mirrors the same rules, so invalid URLs are rejected client-side before a request is made.

## Examples

```http
GET /v1/tickets?filter[status]=open,assigned&filter[priority]=P1,P2&filter[assignee_id]=unassigned&sort=-priority_score&per_page=50
GET /v1/tickets?search="password reset" -invoice&filter[created_between]=2026-09-01,2026-09-17
GET /v1/tickets?filter[sla_state]=warning,breached&sort=sla_due_at&include=contact,category
GET /v1/tickets?filter[assignee_id]=me&filter[status]=active&filter[team_id]=none,019…&filter[has_duplicate_suggestion]=true
GET /v1/tickets/019.../history?per_page=50&cursor=eyJjcmVhdGVkX2F0Ijo…
GET /v1/contacts?search=arj&filter[organization_id]=019...
```

Performance expectations and the supporting indexes are in [08-database/indexing.md](../08-database/indexing.md).
