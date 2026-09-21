terraform {
  required_providers {
    hcloud = { source = "hetznercloud/hcloud", version = "~> 1.68" }
  }
}

# Hetzner DNS through the Cloud API; delegate the zone with NS records at the parent zone's DNS host.
resource "hcloud_zone" "this" {
  name = var.zone
  mode = "primary"
  ttl  = 300
}

resource "hcloud_zone_rrset" "a" {
  for_each = merge(var.records, var.wildcard ? { "*" = values(var.records)[0] } : {})
  zone     = hcloud_zone.this.name
  name     = each.key
  type     = "A"
  ttl      = 300
  records  = [{ value = each.value }]
}
