output "fqdns" { value = [for k in keys(hcloud_zone_rrset.a) : k == "@" ? var.zone : "${k}.${var.zone}"] }
output "name_servers" { value = hcloud_zone.this.authoritative_nameservers }
