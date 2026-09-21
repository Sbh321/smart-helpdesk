output "network_id" { value = digitalocean_vpc.this.id }
output "subnet_cidr" { value = digitalocean_vpc.this.ip_range }
