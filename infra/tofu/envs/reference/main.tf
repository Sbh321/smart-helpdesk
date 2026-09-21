# Reference cloud environment: one VM, a bucket, a firewall, a network and DNS on DigitalOcean.
# To use another provider, change the provider block and the five `source` lines to
# ../../providers/{aws,gcp,hetzner}/<module> (terraform.md §Reference environment).
terraform {
  required_version = ">= 1.12"
  required_providers {
    digitalocean = { source = "digitalocean/digitalocean", version = "~> 2.100" }
    local        = { source = "hashicorp/local", version = "~> 2.9" }
  }

  encryption {
    key_provider "pbkdf2" "local" {
      passphrase = var.state_passphrase
    }
    method "aes_gcm" "local" {
      keys = key_provider.pbkdf2.local
    }
    state {
      method = method.aes_gcm.local
    }
    plan {
      method = method.aes_gcm.local
    }
  }
}

provider "digitalocean" {
  token             = var.do_token
  spaces_access_id  = var.spaces_access_id
  spaces_secret_key = var.spaces_secret_key
}

locals {
  hosts = ["@", "app", "api", "admin", "monitor", "docs", "files", "mail"] # ADR-0021
}

module "network" {
  source = "../../providers/digitalocean/network"
  name   = var.name
  region = var.region
  cidr   = "10.10.0.0/24"
}

module "storage" {
  source     = "../../providers/digitalocean/storage"
  name       = "${var.name}-files"
  region     = var.region
  versioning = true
}

module "compute" {
  source      = "../../providers/digitalocean/compute"
  name        = var.name
  region      = var.region
  size        = var.size
  image       = var.image
  network_id  = module.network.network_id
  ssh_key_ids = var.ssh_key_ids
  volume_gb   = 40
  user_data   = templatefile("${path.root}/../../cloud-init/docker-host.yaml", { deploy_ssh_key = var.deploy_ssh_public_key })
}

module "firewall" {
  source      = "../../providers/digitalocean/firewall"
  name        = var.name
  network_id  = module.network.network_id
  compute_ids = [module.compute.id]
  ssh_cidrs   = var.ssh_cidrs
}

module "dns" {
  source   = "../../providers/digitalocean/dns"
  zone     = var.platform_domain
  records  = { for h in local.hosts : h => module.compute.ipv4 }
  wildcard = false
}

resource "local_sensitive_file" "inventory" {
  filename        = "${path.root}/../../../ansible/inventory/reference.ini"
  file_permission = "0600"
  content = templatefile("${path.root}/inventory.tmpl", {
    host            = var.name
    ipv4            = module.compute.ipv4
    platform_domain = var.platform_domain
    bucket          = module.storage.bucket
    s3_endpoint     = module.storage.endpoint
    s3_region       = module.storage.region
    s3_key          = module.storage.access_key
    s3_secret       = module.storage.secret_key
  })
}
