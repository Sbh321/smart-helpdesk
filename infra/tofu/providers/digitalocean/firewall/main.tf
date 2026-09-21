terraform {
  required_providers {
    digitalocean = { source = "digitalocean/digitalocean", version = "~> 2.100" }
  }
}

# DigitalOcean cloud firewalls attach to droplets, not to the VPC; network_id is part of the contract only.
resource "digitalocean_firewall" "this" {
  name        = var.name
  droplet_ids = [for id in var.compute_ids : tonumber(id)]

  inbound_rule {
    protocol         = "tcp"
    port_range       = "22"
    source_addresses = var.ssh_cidrs
  }

  dynamic "inbound_rule" {
    for_each = { http = ["tcp", "80"], https = ["tcp", "443"], h3 = ["udp", "443"], smtp = ["tcp", "25"] }
    content {
      protocol         = inbound_rule.value[0]
      port_range       = inbound_rule.value[1]
      source_addresses = ["0.0.0.0/0", "::/0"]
    }
  }

  dynamic "outbound_rule" {
    for_each = ["tcp", "udp"]
    content {
      protocol              = outbound_rule.value
      port_range            = "1-65535"
      destination_addresses = ["0.0.0.0/0", "::/0"]
    }
  }

  outbound_rule {
    protocol              = "icmp"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
}
