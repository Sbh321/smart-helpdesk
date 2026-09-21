output "fqdns" { value = [for r in cloudflare_dns_record.a : r.name] }
# The parent zone is already delegated to Cloudflare; nothing to delegate for the platform domain.
output "name_servers" { value = [] }
