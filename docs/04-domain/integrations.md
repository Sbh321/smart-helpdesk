# Integrations domain (developer platform)

## MVP feature set (minimum meaningful)

1. **REST API** `/v1` for tickets, comments, attachments (metadata + presigned upload), contacts, organisations, teams, agents (read), categories, tags, SLA policies (read), webhooks (self-service). Conventions: [07-api/conventions.md](../07-api/conventions.md).
2. **OAuth2 client credentials** per tenant via Laravel Passport; each client is bound to a tenant and a permission set (scopes mirror permission names). Decision and alternatives: [ADR-0007](../adr/0007-authentication-and-oauth.md).
3. **OpenAPI 3.1 document** generated from code and served with an interactive UI at `/docs/api` ([ADR-0010](../adr/0010-api-documentation.md)).
4. **Outbound webhooks** with signing, retries and delivery log ([07-api/webhooks.md](../07-api/webhooks.md)).

Deferred to V1: authorization-code OAuth for user-delegated apps, personal access tokens, inbound email, marketplace, plugin SDK, API usage analytics.

## Entities

```mermaid
erDiagram
    TENANT ||--o{ OAUTH_CLIENT : owns
    TENANT ||--o{ WEBHOOK_SUBSCRIPTION : owns
    WEBHOOK_SUBSCRIPTION ||--o{ WEBHOOK_DELIVERY : "receives"
    OAUTH_CLIENT {
        uuid id "Passport client"
        uuid tenant_id
        string name
        jsonb scopes
        timestamptz revoked_at
    }
    WEBHOOK_SUBSCRIPTION {
        uuid id
        uuid tenant_id
        string url
        string_array events
        string secret_encrypted
        bool is_active
        int consecutive_failures
        timestamptz disabled_at
    }
    WEBHOOK_DELIVERY {
        uuid id
        uuid subscription_id
        string event_type
        uuid event_id "for idempotency at receiver"
        jsonb payload
        int attempt
        int response_status
        text response_excerpt
        enum state "pending | succeeded | failed | dead"
        timestamptz next_attempt_at
    }
```

## Event catalogue (MVP)

`ticket.created`, `ticket.updated`, `ticket.assigned`, `ticket.status_changed`, `ticket.priority_changed`, `ticket.resolved`, `ticket.closed`, `ticket.comment_added` (public only), `ticket.sla_breached`, `contact.created`, `contact.updated`.

Each webhook payload: `{ id, type, tenant_id, occurred_at, data: { <resource> }, api_version: "v1" }`. The `data` object reuses the same JSON shape as the REST resource so integrators learn one schema.

## Tenant-scoping of API clients

A token issued to a client can only act inside the client's tenant; the tenant is taken from the client record, never from the request, so a leaked client cannot switch tenants. Client tokens have no user; audit entries record `actor_type = api_client`.
