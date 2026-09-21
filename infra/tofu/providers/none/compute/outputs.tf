output "id" { value = var.name }
output "ipv4" { value = var.existing_ipv4 }
output "ipv6" { value = var.existing_ipv6 }
output "private_ipv4" { value = coalesce(var.existing_private_ipv4, var.existing_ipv4) }
