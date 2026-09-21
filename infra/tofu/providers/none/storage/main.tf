# RustFS on the VM (storage_profile=true) or a bucket the operator already has; nothing is created.
# Leave existing_endpoint empty for RustFS; Ansible then generates the keys itself.
variable "existing_endpoint" {
  type    = string
  default = ""
}

variable "existing_access_key" {
  type      = string
  default   = ""
  sensitive = true
}

variable "existing_secret_key" {
  type      = string
  default   = ""
  sensitive = true
}
