output "fqdns" { value = [for r in google_dns_record_set.a : trimsuffix(r.name, ".")] }
output "name_servers" { value = google_dns_managed_zone.this.name_servers }
