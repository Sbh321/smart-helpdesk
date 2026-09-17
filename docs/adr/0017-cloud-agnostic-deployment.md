# ADR-0017 Cloud-agnostic deployment targets

**Status:** Accepted (2026-09-17). Supersedes the "DigitalOcean as first reference provider" clause of [ADR-0012](0012-deployment-architecture.md); everything else in ADR-0012 stands.

## Context

The owner requires hosting on any VPS or VM: self-managed servers, AWS, DigitalOcean, GCP, Hetzner or others, with no dependency on one provider. The application already depends only on Docker, PostgreSQL, Valkey, an S3-compatible endpoint and SMTP.

## Decision

1. **The unit of deployment is "a Linux VM with Docker"**; everything above it is provider-independent. Ansible is the universal path: given any reachable Ubuntu/Debian host, `site.yml` installs Docker, writes secrets, runs the Compose stack, migrates and seeds. This is the only path that is mandatory and tested in the MVP.
2. **OpenTofu provisioning is optional and pluggable.** The five module contracts (`network`, `firewall`, `compute`, `storage`, `dns`) are provider-neutral; provider implementations exist for DigitalOcean, Hetzner, AWS and GCP as thin wrappers, plus a `none` provider for an existing VPS (inventory written by hand). Only the provider the owner has an account for is applied end-to-end in the MVP; the others are reviewed but marked untested in `infra/tofu/README.md`.
3. **Object storage is any S3-compatible endpoint** (RustFS/Garage on the VM, AWS S3, Spaces, GCS with the S3-interoperability API, R2, B2); **mail is any SMTP relay or the bundled mail server**; **DNS is any provider** (the app only needs records; on-demand TLS handles certificates).
4. Provider-specific services (managed databases, load balancers, IAM) are not used in the MVP; they are V1 options per provider.

## Alternatives considered

Single reference cloud (rejected by the owner); Kubernetes for portability (out of scope, adds operations burden); provider SDKs in the application (violates NFR-PORT-01).

## Consequences

One tested path (Ansible on a VM) plus optional provisioning; documentation states the per-provider prerequisites (open ports 25/80/443, PTR record for mail, S3 endpoint details). The economic feasibility section quotes a VM price range rather than one provider's price.

## Migration / future considerations

Terraform modules for managed PostgreSQL/object storage per provider; multi-VM topology; Kubernetes only past the criteria in [roadmap/10-future-architecture.md](../../roadmap/10-future-architecture.md).
