terraform {
  required_providers {
    google = { source = "hashicorp/google", version = "~> 8.3" }
  }
}

# Creates a Cloud DNS zone for the platform domain; delegate it with NS records at the parent zone.
resource "google_dns_managed_zone" "this" {
  name     = replace(var.zone, ".", "-")
  dns_name = "${var.zone}."
}

resource "google_dns_record_set" "a" {
  for_each     = merge(var.records, var.wildcard ? { "*" = values(var.records)[0] } : {})
  managed_zone = google_dns_managed_zone.this.name
  name         = each.key == "@" ? "${var.zone}." : "${each.key}.${var.zone}."
  type         = "A"
  ttl          = 300
  rrdatas      = [each.value]
}
