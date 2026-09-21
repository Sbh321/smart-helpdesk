variable "name" {
  type    = string
  default = "helpdesk"
}

variable "ipv4" {
  type        = string
  description = "Address of the existing VM."
}

variable "platform_domain" {
  type    = string
  default = "shp.subhambhandari.com.np"
}

variable "s3_endpoint" {
  type        = string
  default     = ""
  description = "Existing S3 endpoint; empty = RustFS on the VM."
}

variable "s3_key" {
  type      = string
  default   = ""
  sensitive = true
}

variable "s3_secret" {
  type      = string
  default   = ""
  sensitive = true
}
