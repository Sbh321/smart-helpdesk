# Contract: storage. One S3-compatible bucket for attachments plus credentials limited to it.
variable "name" {
  type        = string
  description = "Bucket name (globally unique on AWS and GCP)."
}

variable "region" {
  type        = string
  description = "Provider region."
}

variable "versioning" {
  type        = bool
  default     = false
  description = "Keep object versions (backups.md §Object storage)."
}
