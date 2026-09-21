output "id" { value = aws_instance.this.id }
output "ipv4" { value = aws_eip.this.public_ip }
output "ipv6" { value = try(aws_instance.this.ipv6_addresses[0], null) }
output "private_ipv4" { value = aws_instance.this.private_ip }
