terraform {
  required_providers {
    digitalocean = { source = "digitalocean/digitalocean", version = "~> 2.100" }
  }
}

# Creates the zone for the platform domain; delegate it with NS records at the parent zone's DNS host.
resource "digitalocean_domain" "this" {
  name = var.zone
}

resource "digitalocean_record" "a" {
  for_each = merge(var.records, var.wildcard ? { "*" = values(var.records)[0] } : {})
  domain   = digitalocean_domain.this.id
  type     = "A"
  name     = each.key
  value    = each.value
  ttl      = 300
}
