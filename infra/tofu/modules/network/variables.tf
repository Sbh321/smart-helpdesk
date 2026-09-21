# Contract: network (docs/09-infrastructure/terraform.md §Module contracts). Every providers/*/network
# folder links this file, so the inputs cannot drift between providers.
variable "name" {
  type        = string
  description = "Resource name prefix, e.g. smart-helpdesk."
}

variable "region" {
  type        = string
  description = "Provider region or location (fra1, eu-central-1, europe-west3, fsn1)."
}

variable "cidr" {
  type        = string
  description = "Private address range of the network, e.g. 10.10.0.0/24."
}
