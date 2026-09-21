# Contract: dns. A zone for the platform domain with one A record per fixed host (ADR-0021).
variable "zone" {
  type        = string
  description = "Platform domain, e.g. shp.subhambhandari.com.np (delegate it from the parent zone)."
}

variable "records" {
  type        = map(string)
  description = "Record name (\"@\" for the apex) to IPv4 address."
}

variable "wildcard" {
  type        = bool
  default     = false
  description = "Also add *.<zone>; not needed for the fixed host list."
}
