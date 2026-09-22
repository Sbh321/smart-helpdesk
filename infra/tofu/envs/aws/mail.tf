# Outbound mail through Amazon SES and the platform's mail DNS (runbooks.md §Amazon SES,
# docs/04-domain/email.md). The bundled Stalwart server on the VM receives mail (MX) and relays every
# outgoing message to SES on 587, because EC2 blocks outbound port 25. The records mirror what the
# application shows in Settings → Email (Mail\Support\DnsRecords).

locals {
  mail_from_domain = "bounce.${var.platform_domain}"
  mail_parent_zone = join(".", slice(split(".", var.platform_domain), 1, length(split(".", var.platform_domain))))
}

data "cloudflare_zone" "mail" {
  filter = { name = local.mail_parent_zone }
}

# ---- SES identity: the platform domain, Easy DKIM, custom MAIL FROM ----

resource "aws_sesv2_email_identity" "domain" {
  email_identity = var.platform_domain

  dkim_signing_attributes {
    next_signing_key_length = "RSA_2048_BIT"
  }
}

resource "aws_sesv2_email_identity_mail_from_attributes" "domain" {
  email_identity         = aws_sesv2_email_identity.domain.email_identity
  mail_from_domain       = local.mail_from_domain
  behavior_on_mx_failure = "USE_DEFAULT_VALUE"
}

# While the account is in the SES sandbox, mail reaches only verified addresses: SES e-mails each one a link.
resource "aws_sesv2_email_identity" "recipient" {
  for_each       = toset(var.ses_verified_recipients)
  email_identity = each.value
}

# ---- SMTP credentials: an IAM user that may only send as the platform domain ----

resource "aws_iam_user" "ses_smtp" {
  name = "${var.name}-ses-smtp"
}

resource "aws_iam_user_policy" "ses_smtp" {
  name = "ses-send-as-platform-domain"
  user = aws_iam_user.ses_smtp.name
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Action    = ["ses:SendRawEmail"]
      Resource  = "*"
      Condition = { StringLike = { "ses:FromAddress" = "*@${var.platform_domain}" } }
    }]
  })
}

# The SMTP password is derived from the secret key for the region (ses_smtp_password_v4); both stay in
# the encrypted state and reach Ansible through `tofu output`.
resource "aws_iam_access_key" "ses_smtp" {
  user = aws_iam_user.ses_smtp.name
}

# ---- DNS in the parent zone, DNS only (a proxied record breaks DKIM and MX) ----

resource "cloudflare_dns_record" "ses_dkim" {
  count   = 3
  zone_id = data.cloudflare_zone.mail.zone_id
  name    = "${aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens[count.index]}._domainkey.${var.platform_domain}"
  type    = "CNAME"
  content = "${aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens[count.index]}.dkim.amazonses.com"
  ttl     = 300
  proxied = false
  comment = "smart-helpdesk SES Easy DKIM (OpenTofu)"
}

resource "cloudflare_dns_record" "mail_from_mx" {
  zone_id  = data.cloudflare_zone.mail.zone_id
  name     = local.mail_from_domain
  type     = "MX"
  content  = "feedback-smtp.${var.region}.amazonses.com"
  priority = 10
  ttl      = 300
  proxied  = false
  comment  = "smart-helpdesk SES MAIL FROM (OpenTofu)"
}

resource "cloudflare_dns_record" "mail_from_spf" {
  zone_id = data.cloudflare_zone.mail.zone_id
  name    = local.mail_from_domain
  type    = "TXT"
  content = "\"v=spf1 include:amazonses.com ~all\""
  ttl     = 300
  proxied = false
  comment = "smart-helpdesk SES MAIL FROM (OpenTofu)"
}

resource "cloudflare_dns_record" "mx" {
  zone_id  = data.cloudflare_zone.mail.zone_id
  name     = var.platform_domain
  type     = "MX"
  content  = "mail.${var.platform_domain}"
  priority = 10
  ttl      = 300
  proxied  = false
  comment  = "smart-helpdesk inbound mail to Stalwart (OpenTofu)"
}

resource "cloudflare_dns_record" "spf" {
  zone_id = data.cloudflare_zone.mail.zone_id
  name    = var.platform_domain
  type    = "TXT"
  content = "\"v=spf1 mx -all\""
  ttl     = 300
  proxied = false
  comment = "smart-helpdesk SPF (OpenTofu)"
}

resource "cloudflare_dns_record" "dmarc" {
  zone_id = data.cloudflare_zone.mail.zone_id
  name    = "_dmarc.${var.platform_domain}"
  type    = "TXT"
  content = "\"v=DMARC1; p=${var.mail_dmarc_policy}; rua=${join(",", concat(["mailto:postmaster@${var.platform_domain}"], var.brevo == null ? [] : ["mailto:rua@dmarc.brevo.com"]))}\""
  ttl     = 300
  proxied = false
  comment = "smart-helpdesk DMARC (OpenTofu)"
}

# Stalwart's own DKIM key: printed by infra/scripts/mail-init.sh on the first run, then set here.
resource "cloudflare_dns_record" "stalwart_dkim" {
  count   = var.stalwart_dkim_selector != "" ? 1 : 0
  zone_id = data.cloudflare_zone.mail.zone_id
  name    = "${var.stalwart_dkim_selector}._domainkey.${var.platform_domain}"
  type    = "TXT"
  # A TXT string holds at most 255 characters: split the record into quoted strings, as Cloudflare
  # stores it (receivers join them), so plans stay clean.
  content = join(" ", [for part in regexall(".{1,255}", "v=DKIM1; k=rsa; h=sha256; p=${var.stalwart_dkim_public_key}") : "\"${part}\""])
  ttl     = 300
  proxied = false
  comment = "smart-helpdesk Stalwart DKIM (OpenTofu)"
}

# ---- Brevo: an alternative SMTP relay while SES production access is pending (runbooks.md §Outbound
# mail: relay). Set var.brevo from the records Brevo lists under Senders, Domains → Domains. ----

resource "cloudflare_dns_record" "brevo_code" {
  count   = var.brevo == null ? 0 : 1
  zone_id = data.cloudflare_zone.mail.zone_id
  name    = var.platform_domain
  type    = "TXT"
  content = "\"brevo-code:${var.brevo.code}\""
  ttl     = 300
  proxied = false
  comment = "smart-helpdesk Brevo domain ownership (OpenTofu)"
}

resource "cloudflare_dns_record" "brevo_dkim" {
  count   = var.brevo == null ? 0 : 2
  zone_id = data.cloudflare_zone.mail.zone_id
  name    = "brevo${count.index + 1}._domainkey.${var.platform_domain}"
  type    = "CNAME"
  content = "b${count.index + 1}.${replace(var.platform_domain, ".", "-")}.dkim.brevo.com"
  ttl     = 300
  proxied = false
  comment = "smart-helpdesk Brevo DKIM (OpenTofu)"
}
