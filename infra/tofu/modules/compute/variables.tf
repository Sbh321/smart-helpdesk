# Contract: compute. One VM with a data volume; Docker is installed later by Ansible, not here.
variable "name" {
  type        = string
  description = "Host name of the VM."
}

variable "region" {
  type        = string
  description = "Provider region or location."
}

variable "size" {
  type        = string
  description = "Provider size for 2 vCPU / 4 GB (s-2vcpu-4gb, t3.medium, e2-medium, cx23)."
}

variable "image" {
  type        = string
  description = "Cloud-init capable Debian 13 or Ubuntu 24.04 image (slug, AMI id or image path)."
}

variable "network_id" {
  type        = string
  description = "network_id output of the network module."
}

variable "ssh_key_ids" {
  type        = list(string)
  description = "Provider SSH key ids or names (GCP: \"user:ssh-ed25519 AAAA…\" lines)."
}

variable "user_data" {
  type        = string
  description = "cloud-init document (cloud-init/docker-host.yaml rendered)."
}

variable "volume_gb" {
  type        = number
  default     = 40
  description = "Disk size in GB (data volume or root disk, depending on the provider)."
}
