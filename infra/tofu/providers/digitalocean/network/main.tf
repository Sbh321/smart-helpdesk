terraform {
  required_providers {
    digitalocean = { source = "digitalocean/digitalocean", version = "~> 2.100" }
  }
}

resource "digitalocean_vpc" "this" {
  name     = var.name
  region   = var.region
  ip_range = var.cidr
}
