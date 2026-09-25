# AWS environment: one EC2 VM in a VPC, a security group and an Elastic IP on AWS, DNS records in the
# Cloudflare zone of the parent domain. Files stay in RustFS on the VM (storage profile), the path the
# M3-14 restore rehearsal covered; the S3 module is left out (terraform.md §AWS environment).
terraform {
  required_version = ">= 1.12"

  # State lives outside the repository: tofu init -backend-config="path=$HOME/.config/smart-helpdesk/tofu/aws.tfstate"
  backend "local" {}
  required_providers {
    aws        = { source = "hashicorp/aws", version = "~> 6.65" }
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 5.25" }
    local      = { source = "hashicorp/local", version = "~> 2.9" }
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

provider "aws" {
  region = var.region
  default_tags {
    tags = { app = "smart-helpdesk", managed_by = "opentofu" }
  }
}

# CLOUDFLARE_API_TOKEN from the environment (Zone → DNS → Edit on the parent zone).
provider "cloudflare" {}

locals {
  hosts = ["@", "app", "api", "admin", "monitor", "docs", "platform-docs", "files", "mail"] # ADR-0021, ADR-0024
}

# Newest official Debian 13 image (owner: Debian, https://wiki.debian.org/Cloud/AmazonEC2Image).
data "aws_ami" "debian" {
  most_recent = true
  owners      = ["136693071363"]
  filter {
    name   = "name"
    values = ["debian-13-amd64-*"]
  }
  filter {
    name   = "architecture"
    values = ["x86_64"]
  }
}

resource "aws_key_pair" "operator" {
  key_name   = "${var.name}-operator"
  public_key = var.operator_ssh_public_key
}

module "network" {
  source = "../../providers/aws/network"
  name   = var.name
  region = var.region
  cidr   = "10.20.0.0/24"
}

module "compute" {
  source      = "../../providers/aws/compute"
  name        = var.name
  region      = var.region
  size        = var.size
  image       = var.image != "" ? var.image : data.aws_ami.debian.id
  network_id  = module.network.network_id
  ssh_key_ids = [aws_key_pair.operator.key_name]
  volume_gb   = var.volume_gb
  user_data   = templatefile("${path.root}/../../cloud-init/docker-host.yaml", { deploy_ssh_key = var.deploy_ssh_public_key })
}

module "firewall" {
  source      = "../../providers/aws/firewall"
  name        = var.name
  network_id  = module.network.network_id
  compute_ids = [module.compute.id]
  ssh_cidrs   = var.ssh_cidrs
}

module "dns" {
  source   = "../../providers/cloudflare/dns"
  zone     = var.platform_domain
  records  = { for h in local.hosts : h => module.compute.ipv4 }
  wildcard = false
}

resource "local_sensitive_file" "inventory" {
  filename        = "${path.root}/../../../ansible/inventory/aws.ini"
  file_permission = "0600"
  content = templatefile("${path.root}/../reference/inventory.tmpl", {
    host            = var.name
    ipv4            = module.compute.ipv4
    platform_domain = var.platform_domain
    bucket          = ""
    s3_endpoint     = ""
    s3_region       = ""
    s3_key          = ""
    s3_secret       = ""
  })
}
