terraform {
  required_providers {
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 5.25" }
  }
}

# DNS in an existing Cloudflare zone for the parent domain (the platform domain is a subdomain of it,
# e.g. shp.subhambhandari.com.np in subhambhandari.com.np), so nothing needs delegating. Records are
# DNS-only: Cloudflare's free certificate covers one label below the zone, not app.shp.…, so Caddy on
# the VM gets its own certificates (terraform.md §Cloudflare DNS).
locals {
  labels = split(".", var.zone)
  parent = join(".", slice(local.labels, 1, length(local.labels)))
}

data "cloudflare_zone" "parent" {
  filter = { name = local.parent }
}

resource "cloudflare_dns_record" "a" {
  for_each = merge(var.records, var.wildcard ? { "*" = values(var.records)[0] } : {})
  zone_id  = data.cloudflare_zone.parent.zone_id
  name     = each.key == "@" ? var.zone : "${each.key}.${var.zone}"
  type     = "A"
  content  = each.value
  ttl      = 300
  proxied  = false
  comment  = "smart-helpdesk (OpenTofu)"
}
