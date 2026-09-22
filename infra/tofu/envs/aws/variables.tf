variable "state_passphrase" {
  type        = string
  sensitive   = true
  description = "State and plan encryption passphrase (TF_VAR_state_passphrase, at least 16 characters)."
}

variable "name" {
  type    = string
  default = "smart-helpdesk"
}

variable "region" {
  type    = string
  default = "ap-south-1"
}

variable "size" {
  type    = string
  default = "t3.medium"
}

variable "image" {
  type        = string
  default     = ""
  description = "AMI id; empty picks the newest official Debian 13 amd64 image."
}

variable "volume_gb" {
  type    = number
  default = 30
}

variable "platform_domain" {
  type    = string
  default = "shp.subhambhandari.com.np"
}

variable "operator_ssh_public_key" {
  type        = string
  description = "Public key for the image's default user (admin on Debian), used only for break-glass access."
}

variable "deploy_ssh_public_key" {
  type        = string
  description = "Public key for the deploy user Ansible connects as."
}

variable "ssh_cidrs" {
  type        = list(string)
  description = "Ranges allowed to reach SSH, e.g. [\"203.0.113.7/32\"]."
}

variable "ses_verified_recipients" {
  type        = list(string)
  default     = []
  description = "Addresses SES may send to while the account is in the sandbox (each gets a verification link)."
}

variable "mail_dmarc_policy" {
  type    = string
  default = "none"
}

variable "stalwart_dkim_selector" {
  type        = string
  default     = ""
  description = "Selector printed by infra/scripts/mail-init.sh; empty until the mail server has run once."
}

variable "stalwart_dkim_public_key" {
  type    = string
  default = ""
}

variable "brevo" {
  type = object({
    code = string
  })
  default     = null
  description = "Brevo as SMTP relay: the brevo-code value Brevo shows (null: no Brevo records)."
}
