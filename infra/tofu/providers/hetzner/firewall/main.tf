terraform {
  required_providers {
    hcloud = { source = "hetznercloud/hcloud", version = "~> 1.68" }
  }
}

resource "hcloud_firewall" "this" {
  name = var.name

  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "22"
    source_ips = var.ssh_cidrs
  }

  dynamic "rule" {
    for_each = { http = ["tcp", "80"], https = ["tcp", "443"], h3 = ["udp", "443"], smtp = ["tcp", "25"] }
    content {
      direction  = "in"
      protocol   = rule.value[0]
      port       = rule.value[1]
      source_ips = ["0.0.0.0/0", "::/0"]
    }
  }
}

resource "hcloud_firewall_attachment" "this" {
  firewall_id = hcloud_firewall.this.id
  server_ids  = [for id in var.compute_ids : tonumber(id)]
}
