output "ipv4" { value = module.compute.ipv4 }
output "ipv6" { value = module.compute.ipv6 }
output "fqdns" { value = module.dns.fqdns }
output "name_servers" { value = module.dns.name_servers }
output "inventory" { value = local_sensitive_file.inventory.filename }
