output "ipv4" { value = module.compute.ipv4 }
output "instance_id" { value = module.compute.id }
output "ami" { value = data.aws_ami.debian.name }
output "fqdns" { value = module.dns.fqdns }
output "inventory" { value = local_sensitive_file.inventory.filename }
