output "fqdns" { value = [for r in aws_route53_record.a : r.fqdn] }
output "name_servers" { value = aws_route53_zone.this.name_servers }
