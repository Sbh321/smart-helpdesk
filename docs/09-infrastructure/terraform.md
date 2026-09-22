# Infrastructure as code (OpenTofu)

Decisions: [ADR-0012](../adr/0012-deployment-architecture.md), [ADR-0017](../adr/0017-cloud-agnostic-deployment.md); research: [01-research/deployment-options.md](../01-research/deployment-options.md). OpenTofu 1.12 (MPL-2.0) **optionally** provisions one VM plus object storage, firewall, network and DNS on whichever provider the operator uses; nothing else. Ansible takes over from there ([ansible.md](ansible.md)).

**Provisioning is optional.** The only required input to deployment is an Ansible inventory pointing at a reachable Linux VM with SSH. A self-managed server, a VPS from any host, or a VM created by hand in a cloud console is deployed with exactly the same playbooks; OpenTofu only saves the clicks.

## Supported targets

| Target | Provider folder | MVP status | Object storage | Notes |
|---|---|---|---|---|
| Existing VM / self-managed VPS / on-prem | `providers/none` via `envs/existing` (creates nothing; writes the inventory) | **tested**: `validate` and offline `plan`; the Ansible path it feeds was rehearsed (ansible.md) | RustFS on the VM or any S3 endpoint | open 22, 80, 443 (+25 for the mail server) |
| DigitalOcean | `providers/digitalocean` via `envs/reference` | `validate` passes; not applied (no account on the build machine) | Spaces | all five contracts native |
| AWS | `providers/aws` via `envs/aws` | **applied** (2026-09-21, §AWS environment): network, compute, firewall; DNS through `providers/cloudflare/dns`; storage/Route 53 not used | RustFS on the VM (S3 module available, not applied) | EC2 + VPC + security group + Elastic IP; port 25 needs an AWS removal request, otherwise use `MAIL_RELAY_HOST` (SES) |
| GCP | `providers/gcp` | `validate` passes; untested | GCS via S3-interoperability HMAC keys | Compute Engine + VPC firewall + Cloud DNS; outbound 25 blocked, use a relay |
| Hetzner | `providers/hetzner` | `validate` passes; untested | Hetzner Object Storage (bucket via `aws` provider with custom endpoint) | cheapest; port 25 opened on request |

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
│   ├── hetzner/{network,firewall,compute,storage,dns}/
│   └── cloudflare/dns/                                        # DNS only: records in an existing parent zone
├── envs/
│   ├── reference/           # DigitalOcean wiring (switch provider by editing the provider block and `source` lines)
│   │   ├── main.tf  variables.tf  outputs.tf  .terraform.lock.hcl
│   │   ├── inventory.tmpl   # Ansible inventory template
│   │   └── terraform.tfvars.example
│   ├── existing/            # provider `none`: only writes infra/ansible/inventory/existing.ini
│   └── aws/                 # the deployed environment: AWS compute + Cloudflare DNS (§AWS environment)
├── cloud-init/docker-host.yaml
└── README.md                # per-folder test status
```

As built, every `providers/<p>/<m>/variables.tf` is a **symbolic link** to `modules/<m>/variables.tf`, so an implementation cannot drift from the contract's inputs; provider-specific extras live in the implementation's `main.tf` (`none/compute`: `existing_ipv4`; `none/storage`: `existing_endpoint` and keys; `hetzner/storage`: `access_key`/`secret_key`, because Hetzner S3 keys are created in the console). `infra/scripts/tofu-check.sh` checks the links and that every implementation declares the contract's outputs.

The neutrality trick: every provider folder implements the same input/output variable names, so `envs/reference/main.tf` changes only its `source` lines to switch clouds.

## Module contracts

`network_id` means "what compute attaches to": the VPC on DigitalOcean, the network on Hetzner, the subnet on AWS and GCP (the firewall looks the VPC or network up from it), empty for `none`. The `dns` implementations create the zone for the platform domain and also output `name_servers`, which the owner delegates to from the parent zone (`subhambhandari.com.np` stays where it is).

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

resource "local_sensitive_file" "inventory" {   # as built: sensitive, since it contains the S3 keys
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

Local `terraform.tfstate` with OpenTofu state encryption (passphrase in `TF_VAR_state_passphrase`, never committed); `.gitignore` excludes `*.tfstate*`, `terraform.tfvars` and `*.tfplan`. Move to a Spaces backend when a second operator exists.

`envs/aws` declares an empty `backend "local" {}` and takes the path at init (`-backend-config="path=$HOME/.config/smart-helpdesk/tofu/aws.tfstate"`), so the encrypted state stays out of the working tree and no personal path is committed. `infra/scripts/tofu-check.sh` validates each folder with a throwaway `TF_DATA_DIR`, so it never reads an operator's initialised backend.

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

## As built and verified (M3-14, 2026-09-21)

Provider versions (resolved by `tofu init`, recorded in [versions.md](../01-research/versions.md)): `digitalocean/digitalocean` 2.101.1 (`~> 2.100`), `hetznercloud/hcloud` 1.69.0 (`~> 1.68`), `hashicorp/aws` 6.65.0 (`~> 6.65`), `hashicorp/google` 8.3.0 (`~> 8.3`), `hashicorp/local` 2.9.1 (`~> 2.9`). Implementation notes that differ from the sketch above:

| Provider | Notes |
|---|---|
| DigitalOcean | `digitalocean_spaces_key` with a read-write grant on the one bucket supplies the application's S3 keys (the provider-level Spaces key only creates the bucket); droplet with IPv6 and a 40 GB ext4 volume; firewall also opens 25/tcp for the mail server |
| AWS | VPC + public subnet + IGW + route table; security group attached to the instance's primary interface; Elastic IP for stable DNS/PTR; encrypted gp3 root disk, IMDSv2 required; IAM user with a bucket-only policy for the keys; Route 53 hosted zone |
| GCP | image must run cloud-init (Ubuntu images do, GCP's Debian images do not); static address; firewall rules by network tag; bucket-scoped service account with an HMAC key for the S3-interoperability API; outbound 25 is blocked by GCP, use `MAIL_RELAY_HOST` |
| Hetzner | subnet in the location's network zone; `hcloud_firewall_attachment`; automounted ext4 volume; DNS with `hcloud_zone` + `hcloud_zone_rrset`; bucket through the `aws` provider with the Hetzner endpoint, keys passed in |
| none | outputs only; `envs/existing` turns an address into `inventory/existing.ini` and lists the DNS names to create by hand |

Verification on the build machine (no cloud credentials): `infra/scripts/tofu-check.sh` passes — `tofu fmt -check -recursive`, contract links and output names, `tofu init -backend=false` + `tofu validate` for all 25 provider modules and both environments — and `tofu -chdir=envs/existing plan -var ipv4=203.0.113.10` plans the inventory file and the eight DNS names. Nothing was applied.

### What the owner runs to apply (DigitalOcean example)

```sh
cd infra/tofu/envs/reference
cp terraform.tfvars.example terraform.tfvars          # do_token, spaces keys, ssh_key_ids, deploy_ssh_public_key
export TF_VAR_state_passphrase='<at least 16 characters, kept in the password manager>'
tofu init && tofu plan -out plan.bin && tofu apply plan.bin
tofu output name_servers                                # add NS records for shp at the subhambhandari.com.np DNS host
cd ../../../ansible && ansible-playbook -i inventory/reference.ini site.yml
PLATFORM_DOMAIN=shp.subhambhandari.com.np SMOKE_DEV=0 ../scripts/smoke.sh
cd ../tofu/envs/reference && tofu destroy                # after the demo, to stop billing
```

For AWS, GCP or Hetzner, copy `envs/reference` to `envs/<provider>`, change the `required_providers` entry, the provider block (credentials from the provider's usual environment variables) and the five `source` lines to `../../providers/<provider>/…`, and set `size`/`image`/`region` to that provider's values (`t3.medium` + a Debian 13 AMI id, `e2-medium` + `ubuntu-os-cloud/ubuntu-2404-lts-amd64`, `cx23` + `debian-13`).

## AWS environment (deployed 2026-09-21)

`envs/aws` wires `providers/aws/{network,compute,firewall}` with `providers/cloudflare/dns`: the owner's domain `subhambhandari.com.np` is on Cloudflare, so the eight fixed hosts (ADR-0021) become records in that zone instead of a delegated Route 53 zone. Files stay in RustFS on the VM (`storage_profile=true`), the path the M3-14 restore rehearsal covered; the S3 module is not used.

| Choice | Value | Why |
|---|---|---|
| Region | `ap-south-1` (Mumbai) | nearest AWS region to Nepal; the account's default |
| Instance | `t3.small` (2 vCPU, 2 GB) + 4 GB swap (`swap_size_mb`) | the account is on the AWS Free plan: `t3.medium` is not eligible (`InvalidParameterCombination`); the eligible 4 GB type (`c7i-flex.large`) costs about US$2 a day of credits against about US$0.55 for `t3.small` |
| Image | newest official Debian 13 amd64 AMI (`data "aws_ami"`, owner `136693071363`) | cloud-init capable; matches the rehearsal target |
| Disk | 30 GB encrypted gp3 | inside the Free plan's EBS allowance |
| SSH | security group allows 22 only from the operator's address (`ssh_cidrs`); the host firewall allows 22 from anywhere | one place to change when the operator's address changes |
| DNS | Cloudflare A records, **DNS only**, TTL 300 | Cloudflare's free certificate covers one label below the zone (`*.subhambhandari.com.np`), not `app.shp.…`; Caddy on the VM obtains Let's Encrypt certificates itself (`tls_mode=acme`) |
| Images | `ghcr.io/sbh321/smart-helpdesk-{backend,proxy}:sha-<commit>` (public packages) | the build workflow pushes `main` and `sha-<commit>` on every push to `main`; a deploy pins the commit |

Mail: EC2 blocks outbound port 25, so the bundled mail server relays through Amazon SES (`mail_profile: true` plus the `mail_relay_*` variables); the SES identity, its DKIM CNAMEs in Cloudflare, SMTP credentials and the sandbox exit are owner steps in [runbooks.md §Outbound mail](../11-operations/runbooks.md#outbound-mail-relay-port-25-blocked). Nothing in `envs/aws` creates SES resources.

Credentials: AWS from the CLI session (`aws login`), Cloudflare from `CLOUDFLARE_API_TOKEN` (a token limited to Zone → DNS → Edit on the parent zone), the state passphrase from `TF_VAR_state_passphrase`. Personal deploy settings (image tag, platform admin e-mail, swap) live in an extra-vars file outside the repository.

```sh
cd infra/tofu/envs/aws
cp terraform.tfvars.example terraform.tfvars      # ssh keys, ssh_cidrs, size (gitignored)
export TF_VAR_state_passphrase="$(cat ~/.config/smart-helpdesk/tofu-state-passphrase)"
export CLOUDFLARE_API_TOKEN=…                     # Zone → DNS → Edit
tofu init -backend-config="path=$HOME/.config/smart-helpdesk/tofu/aws.tfstate"
tofu plan -out aws.tfplan && tofu apply aws.tfplan    # writes infra/ansible/inventory/aws.ini
cd ../../../ansible
ansible-galaxy install -r requirements.yml -p galaxy_roles && ansible-galaxy collection install -r requirements.yml -p collections
ansible-playbook -i inventory/aws.ini site.yml -e @$HOME/.config/smart-helpdesk/aws-vars.yml
# a new release: set app_version to the new sha-<commit> tag, then
ansible-playbook -i inventory/aws.ini deploy.yml -e @$HOME/.config/smart-helpdesk/aws-vars.yml
cd ../tofu/envs/aws && tofu destroy                 # removes the VM, Elastic IP and DNS records
```

**Mail** (`envs/aws/mail.tf`, 2026-09-22): SES domain identity with Easy DKIM and MAIL FROM `bounce.<platform domain>`, sandbox recipients (`ses_verified_recipients`), a send-only IAM user whose SES SMTP password OpenTofu derives, and the Cloudflare records MX (→ `mail.<platform domain>`), SPF `v=spf1 mx -all`, DMARC, the three SES DKIM CNAMEs, the MAIL FROM MX and SPF, and Stalwart's DKIM TXT once `stalwart_dkim_selector`/`stalwart_dkim_public_key` are set. Ansible then runs with `mail_profile: true` and `mail_relay_*` from `tofu output` (runbooks.md §Amazon SES).

