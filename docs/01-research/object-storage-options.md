# Object storage options

Researched 2026-09-17. **This research changes a stated assumption: MinIO is no longer a viable choice.** Decision in [ADR-0008](../adr/0008-object-storage.md); design in [03-architecture/storage.md](../03-architecture/storage.md).

## MinIO status (verified)

- github.com/minio/minio is **archived**; README: "THIS REPOSITORY IS NO LONGER MAINTAINED", pointing to AIStor Free (single-node, not open source) and AIStor Enterprise (reported from ~$96k/yr).
- Last release `RELEASE.2025-10-15T17-29-55Z`; no CVE patching since; community Docker images discontinued; the admin console was removed from the community edition in May 2025.
- Laravel 13's filesystem docs now use **RustFS** as the S3-compatible example endpoint; Laravel Sail has a `rustfs` service PR.

Conclusion: an archived, unpatched store cannot hold customer attachments or be handed to an on-prem customer.

## Alternatives for self-hosted S3-compatible storage

| Option | Version | License | Finding |
|---|---|---|---|
| **RustFS** | 1.0.0 (2026-09-16), 32.9k stars, very active | Apache-2.0 | Single binary, MinIO-style API on :9000 and console on :9001, versioning, replication, object lock, event notifications. Documented by Laravel. **1.0 is days old**: pin an exact tag. |
| **Garage** (Deuxfleurs) | 2.4.1 (2026-09-08) | AGPL-3.0 | Small single binary designed for small self-hosted clusters; presigned URLs, multipart and CORS supported; no bucket policies/ACL, no versioning, no SSE (none needed by us). AGPL is mere aggregation when shipped as a separate container, but some procurement teams flag it. |
| SeaweedFS | 4.47 | Apache-2.0 | Excellent at scale but master+volume+filer+s3 gateway is too many parts for a customer's Compose file. |
| Ceph RGW | — | LGPL | Petabyte-scale with an ops team; absurd here. |
| Zenko CloudServer | — | Apache-2.0 | Low momentum. |
| LocalStack S3 | — | Apache-2.0 | Dev/test only. |
| VersityGW | — | Apache-2.0 | S3 over a POSIX filesystem/NAS; niche but interesting for on-prem customers with a NAS. |

## Cloud (SaaS) options

Any S3-compatible service through the same `s3` disk: Cloudflare R2 (zero egress, good for repeatedly downloaded attachments), Backblaze B2 (cheapest at rest), Hetzner Object Storage (EU residency, same-DC as Hetzner compute), AWS S3 (least surprising, egress cost).

## Decision

- **Dev and on-prem default: RustFS** (pinned tag) as an optional Compose service; **Garage** documented as the AGPL-tolerant alternative; **bring-your-own S3 endpoint** is the recommended on-prem configuration for organisations that already run one.
- **Cloud: the provider's S3-compatible store**, R2 preferred for the reference deployment.
- The application only ever talks to Laravel's `s3` disk (`league/flysystem-aws-s3-v3` 3.35.x): `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true` for self-hosted, `false` for AWS/R2.
- Uploads: `Storage::temporaryUploadUrl()` presigned PUT with **server-generated keys** `tenants/{tenant_id}/tickets/{ticket_id}/{uuid}`; bucket CORS for the SPA origin; server-side finalisation validates size and real MIME (`finfo`); downloads via `temporaryUrl()` with `ResponseContentDisposition`.
- **This deviates from the brief's "MinIO" wording and is listed as an open decision for confirmation** in [roadmap/08-decisions-open-questions.md](../../roadmap/08-decisions-open-questions.md).

## Sources

github.com/minio/minio (archived flag, README, releases); min.io/pricing; laravel.com/docs/13.x/filesystem; github.com/laravel/sail/pull/822; github.com/rustfs/rustfs; garagehq.deuxfleurs.fr/documentation/reference-manual/s3-compatibility; github.com/seaweedfs/seaweedfs; packagist.org/packages/league/flysystem-aws-s3-v3.
