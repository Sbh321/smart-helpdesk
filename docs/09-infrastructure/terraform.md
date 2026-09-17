# Infrastructure as code (OpenTofu)

Decisions: [ADR-0012](../adr/0012-deployment-architecture.md), [ADR-0017](../adr/0017-cloud-agnostic-deployment.md); research: [01-research/deployment-options.md](../01-research/deployment-options.md). OpenTofu 1.12 (MPL-2.0) **optionally** provisions one VM plus object storage, firewall, network and DNS on whichever provider the operator uses; nothing else. Ansible takes over from there ([ansible.md](ansible.md)).

**Provisioning is optional.** The only required input to deployment is an Ansible inventory pointing at a reachable Linux VM with SSH. A self-managed server, a VPS from any host, or a VM created by hand in a cloud console is deployed with exactly the same playbooks; OpenTofu only saves the clicks.

## Supported targets

| Target | Provider folder | MVP status | Object storage | Notes |
|---|---|---|---|---|
| Existing VM / self-managed VPS / on-prem | `providers/none` (writes nothing; inventory by hand) | **tested** (primary path) | RustFS on the VM or any S3 endpoint | open 22, 80, 443 (+25 for the mail server) |
| DigitalOcean | `providers/digitalocean` | reviewed; apply-tested only if the owner has an account | Spaces | all five contracts native |
| AWS | `providers/aws` | reviewed, untested | S3 | EC2 + VPC + security group + S3 + Route 53; port 25 needs an AWS removal request, otherwise use `MAIL_RELAY_HOST` (SES) |
| GCP | `providers/gcp` | reviewed, untested | GCS via S3-interoperability HMAC keys | Compute Engine + VPC firewall + Cloud DNS; outbound 25 blocked, use a relay |
| Hetzner | `providers/hetzner` | reviewed, untested | Hetzner Object Storage (bucket via `aws` provider with custom endpoint) | cheapest; port 25 opened on request |

The owner applies whichever provider they have credentials for; the others remain untested implementations of the same contracts, marked as such in `infra/tofu/README.md`.

Time budget: **≤ 1.5 days** in milestone 3, including a first `apply` and one destroy/recreate.

## Layout

```text
infra/tofu/
├── modules/                 # provider-neutral contracts: variables.tf + outputs.tf only (no resources)
│   ├── network/
│   ├── firewall/
│   ├── compute/
│   ├── storage/
│   └── dns/
├── providers/
│   ├── none/                                                  # existing VM: outputs from variables, no resources
│   ├── digitalocean/{network,firewall,compute,storage,dns}/   # implementations of the contracts
│   ├── aws/{network,firewall,compute,storage,dns}/
│   ├── gcp/{network,firewall,compute,storage,dns}/
│   └── hetzner/{network,firewall,compute,storage,dns}/
├── envs/
│   └── reference/
│       ├── main.tf          # wires the five modules from one provider folder
│       ├── variables.tf
│       ├── outputs.tf
│       ├── inventory.tmpl   # Ansible inventory template
│       └── terraform.tfvars.example
└── cloud-init/docker-host.yaml
```

The neutrality trick: every provider folder implements the same input/output variable names, so `envs/reference/main.tf` changes only its `source` lines to switch clouds.

## Module contracts

| Module | Inputs | Outputs |
|---|---|---|
| `network` | `name`, `region`, `cidr` | `network_id`, `subnet_cidr` |
| `firewall` | `name`, `network_id`, `ssh_cidrs` (default `0.0.0.0/0`), `compute_ids` | `firewall_id` |
| `compute` | `name`, `region`, `size`, `image`, `network_id`, `ssh_key_ids`, `user_data`, `volume_gb` | `id`, `ipv4`, `ipv6`, `private_ipv4` |
| `storage` | `name`, `region`, `versioning` (bool) | `bucket`, `endpoint`, `region`, `access_key`, `secret_key` (sensitive) |
| `dns` | `zone`, `records` (map of name → ipv4), `wildcard` (bool) | `fqdns` |

## Example implementation: DigitalOcean (sketch)

The AWS, GCP and Hetzner folders follow the same shape with their provider's resources (`aws_instance`/`aws_security_group`/`aws_s3_bucket`/`aws_route53_record`; `google_compute_instance`/`google_compute_firewall`/`google_storage_bucket`/`google_dns_record_set`; `hcloud_*`).

```hcl
# providers/digitalocean/compute/main.tf
resource "digitalocean_droplet" "this" {
  name      = var.name
  region    = var.region
  size      = var.size            # s-2vcpu-4gb
  image     = var.image           # debian-13-x64
  vpc_uuid  = var.network_id
  ssh_keys  = var.ssh_key_ids
  user_data = var.user_data
  monitoring = true
  tags      = ["smart-helpdesk"]
}
resource "digitalocean_volume" "data" {
  region = var.region
  name   = "${var.name}-data"
  size   = var.volume_gb
}
resource "digitalocean_volume_attachment" "data" {
  droplet_id = digitalocean_droplet.this.id
  volume_id  = digitalocean_volume.data.id
}
output "id"           { value = digitalocean_droplet.this.id }
output "ipv4"         { value = digitalocean_droplet.this.ipv4_address }
output "ipv6"         { value = digitalocean_droplet.this.ipv6_address }
output "private_ipv4" { value = digitalocean_droplet.this.ipv4_address_private }
```

Other resources: `digitalocean_vpc` (network), `digitalocean_firewall` with inbound 22/80/443 TCP and 443 UDP (firewall), `digitalocean_spaces_bucket` + `digitalocean_spaces_bucket_cors_configuration` (storage; Spaces keys come from `SPACES_ACCESS_KEY_ID`/`SPACES_SECRET_ACCESS_KEY` provider config and are passed through as outputs), `digitalocean_domain` + `digitalocean_record` for `@`, `admin` and `*` (dns). Provider `digitalocean/digitalocean ~> 2.100`.

### Hetzner notes

`hetznercloud/hcloud ~> 1.68` covers `hcloud_network`, `hcloud_firewall`, `hcloud_server`, `hcloud_volume` and DNS via `hcloud_zone`/records. It has **no bucket resource**: the `storage` implementation uses the `aws` provider with a custom endpoint (`https://fsn1.your-objectstorage.com`, `skip_credentials_validation`, `s3_use_path_style = false`) to create the bucket, which means a second provider block and Hetzner S3 credentials. Roughly 2.5× cheaper than DigitalOcean.

## Reference environment (one provider at a time)

```hcl
# envs/reference/main.tf
terraform {
  required_version = ">= 1.12"
  required_providers { digitalocean = { source = "digitalocean/digitalocean", version = "~> 2.100" } }
  encryption {
    key_provider "pbkdf2" "local" { passphrase = var.state_passphrase }
    method "aes_gcm" "local" { keys = key_provider.pbkdf2.local }
    state { method = method.aes_gcm.local }
  }
}

provider "digitalocean" {
  token             = var.do_token
  spaces_access_id  = var.spaces_access_id
  spaces_secret_key = var.spaces_secret_key
}

module "network"  { source = "../../providers/digitalocean/network";  name = var.name; region = var.region; cidr = "10.10.0.0/24" }
module "storage"  { source = "../../providers/digitalocean/storage";  name = "${var.name}-files"; region = var.region; versioning = false }
module "compute"  {
  source      = "../../providers/digitalocean/compute"
  name        = var.name
  region      = var.region
  size        = "s-2vcpu-4gb"
  image       = "debian-13-x64"
  network_id  = module.network.network_id
  ssh_key_ids = var.ssh_key_ids
  volume_gb   = 40
  user_data   = templatefile("${path.root}/../../cloud-init/docker-host.yaml", { deploy_ssh_key = var.deploy_ssh_public_key })
}
module "firewall" { source = "../../providers/digitalocean/firewall"; name = var.name; network_id = module.network.network_id; compute_ids = [module.compute.id]; ssh_cidrs = var.ssh_cidrs }
module "dns" {
  source   = "../../providers/digitalocean/dns"
  zone     = var.platform_domain          # shp.subhambhandari.com.np
  records  = { for h in ["@", "app", "api", "admin", "monitor", "docs", "files", "mail"] : h => module.compute.ipv4 }
  wildcard = false                        # fixed host list (ADR-0021)
}

resource "local_file" "inventory" {
  filename        = "${path.root}/../../../ansible/inventory/reference.ini"
  file_permission = "0600"
  content = templatefile("${path.root}/inventory.tmpl", {
    ipv4            = module.compute.ipv4
    platform_domain = var.platform_domain
    bucket          = module.storage.bucket
    s3_endpoint     = module.storage.endpoint
    s3_region       = module.storage.region
    s3_key          = module.storage.access_key
    s3_secret       = module.storage.secret_key
  })
}
```

```ini
# inventory.tmpl
[helpdesk]
reference ansible_host=${ipv4} ansible_user=deploy

[helpdesk:vars]
platform_domain=${platform_domain}
storage_profile=false
s3_bucket=${bucket}
s3_endpoint=${s3_endpoint}
s3_region=${s3_region}
s3_key=${s3_key}
s3_secret=${s3_secret}
tls_mode=acme
```

This generated inventory is the whole OpenTofu → Ansible handoff. No provisioners, no `ansible` provider.

### cloud-init

```yaml
#cloud-config
users:
  - name: deploy
    groups: [sudo]
    sudo: ["ALL=(ALL) NOPASSWD:ALL"]
    shell: /bin/bash
    ssh_authorized_keys: ["${deploy_ssh_key}"]
package_update: true
packages: [ca-certificates, curl, python3]     # python3 for Ansible; Docker is installed by Ansible (geerlingguy.docker)
runcmd:
  - sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
  - systemctl restart ssh
```

Docker is deliberately left to Ansible so the on-prem and cloud paths are identical from the first playbook onward.

## State

Local `terraform.tfstate` with OpenTofu state encryption (passphrase in `TF_VAR_state_passphrase`, never committed); `.gitignore` excludes `*.tfstate*` and `*.tfvars`. Move to a Spaces backend when a second operator exists.

## Commands

```sh
cd infra/tofu/envs/reference
cp terraform.tfvars.example terraform.tfvars      # fill do_token, spaces keys, ssh key ids, platform_domain
tofu init
tofu plan
tofu apply
cd ../../../ansible && ansible-playbook -i inventory/reference.ini site.yml
tofu destroy                                        # teardown after the demo to stop billing
```

`just deploy reference` chains apply + site.yml.

## Cost estimate (single-VM deployment)

The minimum is one 2 vCPU / 4 GB VM with 40 GB disk plus a domain; object storage can live on the VM (RustFS) at no extra cost. Typical monthly prices for that size range from about €8–12 (Hetzner, many regional VPS hosts) to about $24–35 (DigitalOcean, AWS, GCP), plus optional managed object storage at a few dollars. The economic-feasibility section of the report quotes this range and the owner's actual host. Prices must be re-checked at the time of writing the report.

## Out of scope for the MVP

Managed PostgreSQL, load balancers, multiple VMs, autoscaling, Kubernetes, CDN, private container registry (GHCR is used), multi-region. Each is a V1 item with an entry criterion in [roadmap/10-future-architecture.md](../../roadmap/10-future-architecture.md).
