output "fqdns" { value = [for r in digitalocean_record.a : r.fqdn] }
output "name_servers" { value = ["ns1.digitalocean.com", "ns2.digitalocean.com", "ns3.digitalocean.com"] }
