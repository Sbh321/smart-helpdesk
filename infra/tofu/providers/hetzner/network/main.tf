terraform {
  required_providers {
    hcloud = { source = "hetznercloud/hcloud", version = "~> 1.68" }
  }
}

locals {
  # Hetzner locations belong to network zones.
  network_zone = lookup({ fsn1 = "eu-central", nbg1 = "eu-central", hel1 = "eu-central", ash = "us-east", hil = "us-west", sin = "ap-southeast" }, var.region, "eu-central")
}

resource "hcloud_network" "this" {
  name     = var.name
  ip_range = var.cidr
}

resource "hcloud_network_subnet" "this" {
  network_id   = hcloud_network.this.id
  type         = "cloud"
  network_zone = local.network_zone
  ip_range     = var.cidr
}
