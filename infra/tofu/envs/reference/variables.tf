variable "state_passphrase" {
  type        = string
  sensitive   = true
  description = "State and plan encryption passphrase (TF_VAR_state_passphrase, at least 16 characters)."
}

variable "do_token" {
  type      = string
  sensitive = true
}

variable "spaces_access_id" {
  type      = string
  sensitive = true
}

variable "spaces_secret_key" {
  type      = string
  sensitive = true
}

variable "name" {
  type    = string
  default = "smart-helpdesk"
}

variable "region" {
  type    = string
  default = "fra1"
}

variable "size" {
  type    = string
  default = "s-2vcpu-4gb"
}

variable "image" {
  type    = string
  default = "debian-13-x64"
}

variable "platform_domain" {
  type    = string
  default = "shp.subhambhandari.com.np"
}

variable "ssh_key_ids" {
  type        = list(string)
  description = "DigitalOcean SSH key ids or fingerprints for the initial root login."
}

variable "deploy_ssh_public_key" {
  type        = string
  description = "Public key for the deploy user Ansible connects as."
}

variable "ssh_cidrs" {
  type    = list(string)
  default = ["0.0.0.0/0"]
}
