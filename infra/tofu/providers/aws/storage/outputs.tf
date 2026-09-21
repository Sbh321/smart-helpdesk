output "bucket" { value = aws_s3_bucket.this.bucket }
output "endpoint" { value = "https://s3.${var.region}.amazonaws.com" }
output "region" { value = var.region }

output "access_key" {
  value     = aws_iam_access_key.app.id
  sensitive = true
}

output "secret_key" {
  value     = aws_iam_access_key.app.secret
  sensitive = true
}
