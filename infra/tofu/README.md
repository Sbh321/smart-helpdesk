# OpenTofu (optional provisioning)

Design and commands: [docs/09-infrastructure/terraform.md](../../docs/09-infrastructure/terraform.md). Provisioning is optional ([ADR-0017](../../docs/adr/0017-cloud-agnostic-deployment.md)); the Ansible playbooks work on any reachable Debian/Ubuntu VM.

| Folder | Status (2026-09-21) |
|---|---|
| `modules/*` | contracts: `variables.tf` (linked by every implementation) and documented outputs |
| `providers/none` | `tofu validate` and `tofu plan` pass; creates nothing, writes the inventory (`envs/existing`) |
| `providers/digitalocean` | `tofu validate` passes; **never applied** (no account configured on the build machine) |
| `providers/aws` | network, compute and firewall **applied** through `envs/aws` (2026-09-21); storage and dns validate only |
| `providers/cloudflare/dns` | **applied** through `envs/aws`: DNS-only records in the existing parent zone |
| `providers/gcp` | `tofu validate` passes; **untested** |
| `providers/hetzner` | `tofu validate` passes; **untested** |
| `envs/reference` | DigitalOcean wiring; `tofu validate` passes; not applied |
| `envs/existing` | provider `none`; `tofu plan` passes offline |
| `envs/aws` | the deployed environment: AWS compute + Cloudflare DNS, state outside the repository (terraform.md §AWS environment) |

Checks without credentials: `infra/scripts/tofu-check.sh` (fmt, contract names, `validate` of every folder).
