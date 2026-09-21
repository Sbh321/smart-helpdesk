output "bucket" {
  description = "Bucket name (AWS_BUCKET)."
  value       = null
}

output "endpoint" {
  description = "S3 endpoint URL (AWS_ENDPOINT, AWS_PRESIGN_ENDPOINT)."
  value       = null
}

output "region" {
  description = "Signing region (AWS_DEFAULT_REGION)."
  value       = null
}

output "access_key" {
  description = "Access key id limited to the bucket."
  value       = null
  sensitive   = true
}

output "secret_key" {
  description = "Secret access key."
  value       = null
  sensitive   = true
}
