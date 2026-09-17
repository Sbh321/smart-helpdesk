# Deployment options

Researched 2026-09-17. Decision in [ADR-0012](../adr/0012-deployment-architecture.md); details in [09-infrastructure](../09-infrastructure/docker.md).

## Application image

| Option | Version | Finding | Verdict |
|---|---|---|---|
| `serversideup/php` (`8.5-fpm-nginx`) | 4.5.1 | nginx + PHP-FPM in one image, healthcheck built in, PUID/PGID for on-prem file ownership, S6 signal handling, `frankenphp` variant available; same image runs app, Horizon, scheduler, Reverb with different commands. | **Chosen** |
| Official `php:8.5-fpm-alpine` + own nginx container | 8.5.10 | Smallest, but we own the extension build, FPM tuning, healthcheck and signal handling. | Fallback |
| FrankenPHP / Octane | 1.12.7 | Fastest, but persistent workers keep container state across requests (tenant context, RLS setting, spatie cache) — a cross-tenant leak class we do not want to police in an MVP. | V1 evaluation |

## Reverse proxy

| Option | Version | Finding |
|---|---|---|
| **Caddy** | 2.11.4 | Automatic HTTPS; internal CA for on-prem without public DNS; on-demand TLS with an `ask` endpoint for tenant subdomains; one config file. **Chosen.** |
| nginx | 1.31 | Manual TLS (certbot sidecar); wildcard needs DNS-01. |
| Traefik | 3.7 | Label-driven, good for dynamic containers; more concepts than we need. |

Subdomain TLS strategy: SaaS uses Caddy on-demand TLS (HTTP-01 per tenant host, allowed hosts checked against the tenants table) or sits behind Cloudflare; on-prem uses single-tenant mode on one hostname with a customer certificate or Caddy's internal CA; dev uses `tls internal` for `*.helpdesk.localhost`.

## Infrastructure as code

| Option | Version | Licence | Finding |
|---|---|---|---|
| **OpenTofu** | 1.12.6 | MPL-2.0 | Drop-in HCL/provider/state compatibility, built-in state encryption. **Chosen.** |
| Terraform | 1.16.3 | BUSL-1.1 (licensor IBM since 2026-03) | Fine to use, but a licence question for a sellable on-prem product. |

Reference cloud (superseded by [ADR-0017](../adr/0017-cloud-agnostic-deployment.md): any VM is a target and provider modules are optional): **DigitalOcean** (Droplet, VPC, Firewall, Spaces bucket, DNS record all native in provider 2.100) at ≈ $30/month for 2 vCPU/4 GB + Spaces. Hetzner (≈ 2.5× cheaper, `hcloud` 1.68 has no bucket resource) documented as target two; AWS rejected as the first target (most boilerplate, highest cost). Module contracts (`network`, `firewall`, `compute`, `storage`, `dns`) are provider-neutral; provider folders implement them.

## Host configuration

Ansible core 2.21 with `community.docker` (`docker_compose_v2`), `ansible.posix`, `community.general`; role `geerlingguy.docker` (active, 2026-08). Roles: `common`, `docker`, `app`, `proxy`, `backup`, `firewall`. Terraform provisions; Ansible configures; Docker packages.

## CI

GitHub Actions, three workflows with `paths` filters (backend, frontend, e2e), PostgreSQL and Valkey service containers with health commands, `shivammathur/setup-php@v2`, composer cache dir cached (never `vendor/`), pnpm cache, Playwright official image `mcr.microsoft.com/playwright:v1.63.0-noble`, E2E via `docker compose up -d --wait`.

## Backups and secrets

Backups: `spatie/laravel-backup` to the S3 disk (DB + attachments together) plus a host `pg_dump` systemd timer; pgBackRest/wal-g overkill; `prodrigestivill/postgres-backup-local` DB-only and quiet. Secrets: Ansible-generated per-host `.env` (mode 0600) and Compose file secrets for the DB password; SOPS/age deferred until a second operator exists.

## Observability

JSON logs to stdout (Monolog JSON formatter with request id and tenant id), Docker `json-file` driver with rotation, spatie/laravel-health JSON endpoint, Horizon dashboard, `/up` for container healthchecks (dependency-free). OpenTelemetry PHP auto-instrumentation is still beta and documents Laravel 9–12 only: V1.

## Sources

github.com/serversideup/docker-php, github.com/dunglas/frankenphp, github.com/caddyserver/caddy, caddyserver.com/docs/automatic-https (on-demand TLS), github.com/opentofu/opentofu, github.com/hashicorp/terraform (LICENSE), registry.terraform.io (digitalocean, hcloud), github.com/hetznercloud/terraform-provider-hcloud/issues/1005, pypi.org (ansible-core), galaxy.ansible.com, github.com/geerlingguy/ansible-role-docker, docs.github.com/actions, github.com/microsoft/playwright/releases, github.com/spatie/laravel-backup, github.com/getsops/sops, opentelemetry.io/docs/languages/php.
