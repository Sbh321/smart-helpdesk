# Depends on the subnet, so a server attached to network_id is created after it.
output "network_id" { value = hcloud_network_subnet.this.network_id }
output "subnet_cidr" { value = hcloud_network_subnet.this.ip_range }
