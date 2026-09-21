output "dns_records_to_create" { value = module.dns.fqdns }
output "inventory" { value = local_sensitive_file.inventory.filename }
