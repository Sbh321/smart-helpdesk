output "bucket" { value = digitalocean_spaces_bucket.this.name }
output "endpoint" { value = "https://${var.region}.digitaloceanspaces.com" }
output "region" { value = var.region }

output "access_key" {
  value     = digitalocean_spaces_key.app.access_key
  sensitive = true
}

output "secret_key" {
  value     = digitalocean_spaces_key.app.secret_key
  sensitive = true
}
