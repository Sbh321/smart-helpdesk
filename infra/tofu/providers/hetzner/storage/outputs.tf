output "bucket" { value = aws_s3_bucket.this.bucket }
output "endpoint" { value = "https://${var.region}.your-objectstorage.com" }
output "region" { value = var.region }

output "access_key" {
  value     = var.access_key
  sensitive = true
}

output "secret_key" {
  value     = var.secret_key
  sensitive = true
}
