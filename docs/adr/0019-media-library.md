# ADR-0019 Media library module

**Status:** Accepted (2026-09-17)

## Context

The owner asked for a feature-rich media library inside the platform. The MVP already needed ticket attachments with presigned direct uploads to S3-compatible storage. Two file models would be a mistake. Research: [mail-and-media-options.md](../01-research/mail-and-media-options.md).

## Decision

1. A **`Media` module** owns all files: `media_items` (tenant, folder, name, key, size, MIME, dimensions, checksum, variants JSONB, uploader, source `upload|email|api|system`, state `pending|ready|trashed`, quota-counted bytes), `media_folders` (tree per tenant), `mediables` (polymorphic links to tickets, comments, tenant branding, future knowledge-base articles), tags via the shared `taggables`.
2. `ticket_attachments` is replaced by media items linked through `mediables`; the intent/complete presigned flow in [storage.md](../03-architecture/storage.md) becomes the Media module's upload API and is reused everywhere.
3. **Variants**: a queued `GenerateImageVariants` job uses intervention/image 4.3 (GD, Imagick when available) to produce `thumb` (256 px), `preview` (1280 px) and keeps the original; PDFs get a first-page thumbnail only when Imagick is present (optional).
4. **Library UI**: Settings → Media library (folder tree, grid/list, search by name/type/tag, metadata panel, upload, move, tag, trash/restore, quota bar) and a "pick from library" dialog in the comment composer and branding settings.
5. **Quota**: per-tenant `storage_quota_bytes` (platform setting default 5 GB) with usage counters maintained on ready/trash/purge; uploads beyond quota are rejected with `quota_exceeded`.
6. Rejected for the MVP: spatie/laravel-medialibrary (server-side ingest model conflicts with direct uploads; would duplicate the model), image editing, video transcoding, CDN, public sharing links.

## Alternatives considered

spatie/laravel-medialibrary (revisit for responsive images in V1); keeping attachments separate from a library (two models, two upload paths); storing files in PostgreSQL (forbidden by the brief).

## Consequences

One file model and one upload path; about one and a half days added (folders/tags/variants/library UI/quota); the report gains a module. Media keys live under `tenants/{id}/media/{item_id}/…` with variant suffixes.

## Migration / future considerations

Responsive images, image editing, virus scanning, CDN signed URLs, per-tenant buckets, knowledge-base assets.
