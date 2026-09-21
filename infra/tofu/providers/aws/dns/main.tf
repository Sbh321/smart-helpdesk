terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 6.65" }
  }
}

# Creates a hosted zone for the platform domain; delegate it with NS records at the parent zone.
resource "aws_route53_zone" "this" {
  name = var.zone
}

resource "aws_route53_record" "a" {
  for_each = merge(var.records, var.wildcard ? { "*" = values(var.records)[0] } : {})
  zone_id  = aws_route53_zone.this.zone_id
  name     = each.key == "@" ? var.zone : "${each.key}.${var.zone}"
  type     = "A"
  ttl      = 300
  records  = [each.value]
}
