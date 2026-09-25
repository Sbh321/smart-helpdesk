# Existing VM, self-managed VPS or on-prem server (provider `none`, the tested path of ADR-0017):
# creates nothing and only writes the Ansible inventory. Doing that by hand from
# infra/ansible/inventory/*.ini.example is equally fine.
terraform {
  required_version = ">= 1.12"
  required_providers {
    local = { source = "hashicorp/local", version = "~> 2.9" }
  }
}

locals {
  hosts = ["@", "app", "api", "admin", "monitor", "docs", "platform-docs", "files", "mail"]
}

module "network" {
  source = "../../providers/none/network"
  name   = var.name
  region = "on-prem"
  cidr   = "10.0.0.0/24"
}

module "storage" {
  source              = "../../providers/none/storage"
  name                = "helpdesk"
  region              = "us-east-1"
  existing_endpoint   = var.s3_endpoint
  existing_access_key = var.s3_key
  existing_secret_key = var.s3_secret
}

module "compute" {
  source        = "../../providers/none/compute"
  name          = var.name
  region        = "on-prem"
  size          = "existing"
  image         = "existing"
  network_id    = module.network.network_id
  ssh_key_ids   = []
  user_data     = ""
  existing_ipv4 = var.ipv4
}

module "firewall" {
  source      = "../../providers/none/firewall"
  name        = var.name
  network_id  = module.network.network_id
  compute_ids = [module.compute.id]
}

module "dns" {
  source  = "../../providers/none/dns"
  zone    = var.platform_domain
  records = { for h in local.hosts : h => module.compute.ipv4 }
}

resource "local_sensitive_file" "inventory" {
  filename        = "${path.root}/../../../ansible/inventory/existing.ini"
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
