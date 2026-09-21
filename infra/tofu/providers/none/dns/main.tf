# Records are created by hand at the DNS provider (production.md §DNS); this only lists the names.
locals {
  names = concat([for k in keys(var.records) : k == "@" ? var.zone : "${k}.${var.zone}"], var.wildcard ? ["*.${var.zone}"] : [])
}
