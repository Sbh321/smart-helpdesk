output "bucket" { value = google_storage_bucket.this.name }
output "endpoint" { value = "https://storage.googleapis.com" }
output "region" { value = var.region }

output "access_key" {
  value     = google_storage_hmac_key.app.access_id
  sensitive = true
}

output "secret_key" {
  value     = google_storage_hmac_key.app.secret
  sensitive = true
}
