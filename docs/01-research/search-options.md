# Search options

Researched 2026-09-17. Decision in [ADR-0011](../adr/0011-search-architecture.md).

## Needs in the MVP

1. Ticket list search box: words in title/description, ticket number, contact name.
2. Contact/agent/tag typeahead with typo tolerance.
3. Candidate retrieval for the duplicate detector (top-K similar recent tickets, then our own scoring).

## Options

| Option | Version / license | Finding | Verdict |
|---|---|---|---|
| PostgreSQL full-text: `tsvector` generated column + GIN, `websearch_to_tsquery` | built in | Single-digit to low-tens of ms over millions of rows; no extra service; `websearch_to_tsquery` handles quotes and negation safely for user input. `tenant_id` predicate combined via bitmap-AND with the btree index (or `btree_gin` later). RLS still applies to raw queries. | **MVP** |
| PostgreSQL `pg_trgm` GIN index on title / contact name / email | extension | Accelerates unanchored `ILIKE` and `similarity()`; typo-tolerant typeahead; also the duplicate pre-filter. | **MVP** |
| Laravel Scout `database` driver | Scout 11.7.0 | `SearchUsingFullText` / `SearchUsingPrefix` attributes; Laravel 13 adds pgvector hybrid search in this driver. Adds a builder concept that fights our filter composition. | V1 hook (if an external engine is ever adopted, Scout is the seam) |
| Meilisearch | 1.53.2, MIT core **and** BUSL-1.1 enterprise parts | Best DX; typo tolerance; a second isolation boundary (tenant filter on every query, no RLS backstop); licence split matters for an on-prem appliance. | V1 if instant-search UX becomes a differentiator |
| Typesense | 30.2, GPL-3.0 | Similar; GPL bundling consideration. | V1 alternative |
| OpenSearch / Elasticsearch | Apache-2.0 | JVM cluster in a customer's Compose file; no first-party Scout driver. | Rejected |
| pgvector semantic search (`whereVectorSimilarTo`) | PG extension | First-party in Laravel 13; needs embeddings (AI provider or local model). | V1 (advanced duplicate detection) |

## Decision

PostgreSQL only: generated `search_vector` column on `tickets` (title weighted A, description weighted B), GIN index; `pg_trgm` GIN indexes on `tickets.title`, `contacts.name`, `contacts.email`, `tags.name`. Implemented as Eloquent query scopes (`Ticket::search($term)`, `Contact::typeahead($term)`) so the SQL is in one place. The duplicate detector's candidate stage uses trigram similarity on title plus FTS rank, limited to the same tenant and to open/recently closed tickets. Reasons: zero infrastructure for on-prem, no index-sync bugs, no second tenant-isolation boundary to prove, and RLS covers raw queries.

## Sources

postgresql.org/docs/17 (textsearch-tables, textsearch-controls, pgtrgm, btree-gin); laravel.com/docs/13.x/scout; github.com/meilisearch/meilisearch LICENSE; github.com/typesense/typesense; github.com/pgvector/pgvector.
