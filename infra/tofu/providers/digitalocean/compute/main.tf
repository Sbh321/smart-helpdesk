terraform {
  required_providers {
    digitalocean = { source = "digitalocean/digitalocean", version = "~> 2.100" }
  }
}

resource "digitalocean_droplet" "this" {
  name       = var.name
  region     = var.region
  size       = var.size  # s-2vcpu-4gb
  image      = var.image # debian-13-x64
  vpc_uuid   = var.network_id
  ssh_keys   = var.ssh_key_ids
  user_data  = var.user_data
  ipv6       = true
  monitoring = true
  tags       = ["smart-helpdesk"]
}

resource "digitalocean_volume" "data" {
  region                  = var.region
  name                    = "${var.name}-data"
  size                    = var.volume_gb
  initial_filesystem_type = "ext4"
}

resource "digitalocean_volume_attachment" "data" {
  droplet_id = digitalocean_droplet.this.id
  volume_id  = digitalocean_volume.data.id
}
