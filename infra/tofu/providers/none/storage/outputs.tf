output "bucket" { value = var.name }
output "endpoint" { value = var.existing_endpoint }
output "region" { value = var.region }

output "access_key" {
  value     = var.existing_access_key
  sensitive = true
}

output "secret_key" {
  value     = var.existing_secret_key
  sensitive = true
}
