# Contract: firewall. Inbound 22 (ssh_cidrs), 80, 443 tcp, 443 udp and 25 (mail) only; outbound open.
variable "name" {
  type        = string
  description = "Resource name prefix."
}

variable "network_id" {
  type        = string
  description = "network_id output of the network module."
}

variable "ssh_cidrs" {
  type        = list(string)
  default     = ["0.0.0.0/0"]
  description = "Ranges allowed to reach SSH."
}

variable "compute_ids" {
  type        = list(string)
  description = "id outputs of the compute module the rules apply to."
}
