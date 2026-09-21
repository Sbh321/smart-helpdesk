# The VM already exists; its addresses are inputs. size, image, ssh_key_ids, user_data and volume_gb
# are accepted for contract compatibility and ignored (prepare the host as cloud-init/docker-host.yaml does).
variable "existing_ipv4" {
  type        = string
  description = "Public or LAN address Ansible connects to."
}

variable "existing_ipv6" {
  type    = string
  default = null
}

variable "existing_private_ipv4" {
  type    = string
  default = null
}
