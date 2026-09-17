# ADR-0008 Object storage: S3-compatible disk; RustFS instead of MinIO for self-hosting

**Status:** Accepted (2026-09-17) — deviates from the brief's MinIO assumption; confirmation requested in [roadmap/08-decisions-open-questions.md](../../roadmap/08-decisions-open-questions.md).

## Context

The brief assumed MinIO. Research ([01-research/object-storage-options.md](../01-research/object-storage-options.md)) shows the MinIO repository is archived, unmaintained since October 2025 and stripped of its console; Laravel's docs now use RustFS as the S3-compatible example.

## Decision

- The application uses **only Laravel's `s3` disk** (`league/flysystem-aws-s3-v3`). Endpoint, path style, region, bucket and credentials are configuration.
- **Development and on-prem default: RustFS** (Apache-2.0, pinned tag) as an optional Compose service; **Garage** documented as an alternative; on-prem customers may point at their own S3 endpoint.
- **Cloud: the provider's S3-compatible storage** (Cloudflare R2 preferred; DigitalOcean Spaces for the reference deployment).
- **Key layout**: `tenants/{tenant_id}/tickets/{ticket_id}/{attachment_uuid}`, `tenants/{tenant_id}/branding/…`, `tenants/{tenant_id}/exports/…`. Keys are always generated server-side.
- **Uploads**: presigned PUT (`temporaryUploadUrl`, 5 min) after a server-side "intent" call that records size limit and expected MIME; a "complete" call verifies object existence, size and real MIME via `finfo` on a HEAD/range read, then activates the attachment. **Downloads**: presigned GET (`temporaryUrl`, 5 min) with `ResponseContentDisposition`. Bucket is private; CORS allows PUT from tenant origins.
- Limits: 25 MB per file, 10 files per comment, allow-list of MIME types; malware scanning is future work.

## Alternatives considered

MinIO (archived), SeaweedFS (too many components for a customer Compose file), Ceph (too heavy), LocalStack (dev only), storing files in PostgreSQL (explicitly forbidden by the brief), local filesystem disk (breaks multi-container and on-prem backup story; kept only for tests).

## Consequences

One code path across all environments; attachments never pass through PHP; on-prem installs need one more container or an existing S3 endpoint. RustFS 1.0 is very new: pin exactly and keep Garage as the tested alternative in milestone 1.

## Migration / future considerations

Per-tenant buckets for dedicated tenants; lifecycle rules for exports; antivirus scan job (ClamAV) in V1; image thumbnails.
