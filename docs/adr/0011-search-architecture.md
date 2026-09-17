# ADR-0011 Search: PostgreSQL full-text and trigram only

**Status:** Accepted (2026-09-17)

## Context

Ticket search, typeahead and duplicate candidate retrieval are needed; external engines add a service to the on-prem stack and a second tenant-isolation boundary. Research: [01-research/search-options.md](../01-research/search-options.md).

## Decision

Stored generated `tickets.search_vector` (title weight A, description weight B) with a GIN index; `pg_trgm` GIN indexes on `tickets.title`, `contacts.name`, `contacts.email`, `tags.name`; queries through Eloquent scopes (`Ticket::search()`, `Contact::typeahead()`), always within the tenant scope and under RLS. No Laravel Scout in the MVP.

## Alternatives considered

Meilisearch (MIT core + BUSL parts; second isolation boundary), Typesense (GPL), OpenSearch (JVM), Scout database driver (adds a builder layer; V1 seam if an engine is adopted).

## Consequences

No extra infrastructure; search semantics are English-stemmed FTS plus trigram fuzziness; multilingual stemming is a V1 topic.

## Migration / future considerations

Scout + Meilisearch for instant-search UX; pgvector semantic similarity for the V1 duplicate detector.
