terraform {
  required_providers {
    hcloud = { source = "hetznercloud/hcloud", version = "~> 1.68" }
  }
}

resource "hcloud_server" "this" {
  name        = var.name
  location    = var.region # fsn1, nbg1, hel1, ash, hil, sin
  server_type = var.size   # cx23 (2 vCPU, 4 GB)
  image       = var.image  # debian-13
  ssh_keys    = var.ssh_key_ids
  user_data   = var.user_data
  labels      = { app = "smart-helpdesk" }

  public_net {
    ipv4_enabled = true
    ipv6_enabled = true
  }

  network {
    network_id = tonumber(var.network_id)
  }
}

resource "hcloud_volume" "data" {
  name      = "${var.name}-data"
  size      = var.volume_gb
  server_id = hcloud_server.this.id
  automount = true
  format    = "ext4"
}
