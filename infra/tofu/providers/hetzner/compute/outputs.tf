output "id" { value = tostring(hcloud_server.this.id) }
output "ipv4" { value = hcloud_server.this.ipv4_address }
output "ipv6" { value = hcloud_server.this.ipv6_address }
output "private_ipv4" { value = one([for n in hcloud_server.this.network : n.ip]) }
