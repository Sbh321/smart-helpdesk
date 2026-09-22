output "ipv4" { value = module.compute.ipv4 }
output "instance_id" { value = module.compute.id }
output "ami" { value = data.aws_ami.debian.name }
output "fqdns" { value = module.dns.fqdns }
output "inventory" { value = local_sensitive_file.inventory.filename }
output "ses_smtp_host" { value = "email-smtp.${var.region}.amazonaws.com" }
output "ses_smtp_username" { value = aws_iam_access_key.ses_smtp.id }
output "ses_smtp_password" {
  value     = aws_iam_access_key.ses_smtp.ses_smtp_password_v4
  sensitive = true
}
output "ses_identity_status" { value = aws_sesv2_email_identity.domain.verified_for_sending_status }
