# Object storage architecture

Decision: [ADR-0008](../adr/0008-object-storage.md). Single Laravel `s3` disk; provider is configuration.

## Configuration matrix

| Environment | Endpoint | `use_path_style_endpoint` | Bucket |
|---|---|---|---|
| Dev (Compose) | `http://rustfs:9000` (console :9001) | true | `helpdesk` |
| On-prem | RustFS/Garage container or customer S3 endpoint | true (self-hosted) / per provider | per install |
| Cloud (any provider) | provider S3-compatible store (S3, Spaces, GCS interop, R2, B2, Hetzner) or RustFS on the VM | false for provider stores, true for self-hosted | per environment |
| Tests | `local` disk in `storage/framework/testing` with fake presigned URLs | — | — |

## Key layout

```text
tenants/{tenant_id}/media/{item_id}/original.{ext}             any media item (ticket attachments included)
tenants/{tenant_id}/media/{item_id}/thumb.webp | preview.webp   generated variants
tenants/{tenant_id}/branding/logo-{hash}.{ext}
tenants/{tenant_id}/backups/...                                  (spatie backup, separate disk/prefix)
```

The Media module stores tenant-relative keys such as `media/{item_id}/original.{ext}`; the configured filesystem tenancy bootstrapper prefixes both the storage and presign disks with `tenants/{tenant_id}/`, yielding the full object keys above exactly once. Clients never supply keys.

## Upload flow (direct browser upload)

```mermaid
sequenceDiagram
    participant SPA
    participant API
    participant S3
    SPA->>API: POST /v1/media/intent {filename, size, mime, attach_to?}
    API->>API: validate size/mime allow-list and quota, create media_items row (state=pending, key)
    API->>S3: temporaryUploadUrl(key, 5 min, ContentType, ContentLength range)
    API-->>SPA: {media_id, url, headers}
    SPA->>S3: PUT file
    SPA->>API: POST /v1/media/{id}/complete
    API->>S3: HEAD key; read first bytes
    API->>API: verify size, sniff MIME (finfo), checksum, dimensions, state=ready, quota += size, link to attach_to, queue variants (or delete + 422)
    API-->>SPA: media resource
```

Pending items older than 1 h are deleted by `media:cleanup`. Comments reference media ids at submit time. Ready items that are not linked anywhere stay in the library (it is a library, not a staging area); trashed items are purged after 30 days.

M2-08 implementation status: the intent/complete API and tenant-scoped metadata tables are in place, with the development migrations applied. Complete reads back the object, checks size and MIME, records checksum/dimensions and increments the quota counter under a lock. The hourly cleanup command is implemented for stale pending/failed items and unlinked trash older than 30 days. The initial browser uploader, Ticket/Comment attachment paths and Media library are implemented but not acceptance-tested yet.

## Download flow

`GET /v1/media/{id}/download` → policy check (tenant scope + `media.view`, and access to a linked ticket when the item is only linked to tickets) → 302 to `temporaryUrl(key, 5 min, ['ResponseContentDisposition' => 'attachment; filename="..."'])`. Never proxy bytes through PHP.

## Validation

- Allow-list: images (png, jpg, gif, webp), pdf, txt, csv, log, docx/xlsx/pptx, zip; deny svg/html/js/exe.
- 25 MB per file; 10 per comment; 50 per ticket.
- Real MIME via `finfo` on the object's first 8 KB; mismatch → reject. The M2-08 implementation currently reads the complete object (bounded at 25 MB) for checksum and image dimensions, and also checks OOXML ZIP entries for DOCX/XLSX/PPTX so a renamed generic ZIP is rejected.
- Bucket CORS: `PUT, GET` from tenant origins; `Content-Type`, `Content-Length` headers.

## Operations

Buckets are private; report exports are ordinary Media items in the `Reports` folder since M3-09 (`media/{id}/original.csv|xlsx`), so there is no `exports/` prefix to expire; they stay until trashed (retention of exports is V1); backups go to a separate disk/prefix with its own credentials on-prem. Malware scanning (ClamAV job) is V1.
