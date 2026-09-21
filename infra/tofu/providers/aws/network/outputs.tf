# Instances attach to the subnet; the firewall derives the VPC from it.
output "network_id" { value = aws_route_table_association.public.subnet_id }
output "subnet_cidr" { value = aws_subnet.public.cidr_block }
