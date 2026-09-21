output "id" {
  description = "Provider id of the VM."
  value       = null
}

output "ipv4" {
  description = "Public IPv4 address (Ansible inventory, DNS A records)."
  value       = null
}

output "ipv6" {
  description = "Public IPv6 address, or null when the provider gives none."
  value       = null
}

output "private_ipv4" {
  description = "Address on the private network."
  value       = null
}
