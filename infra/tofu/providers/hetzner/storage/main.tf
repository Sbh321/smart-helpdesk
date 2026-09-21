# Hetzner Object Storage has no hcloud resource: the bucket is created through its S3 API with the aws
# provider, configured by the environment with the Hetzner endpoint (terraform.md §Hetzner notes).
# Access keys are created in the Hetzner console; pass the same pair here so they reach the inventory.
terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 6.65" }
  }
}

variable "access_key" {
  type      = string
  sensitive = true
}

variable "secret_key" {
  type      = string
  sensitive = true
}

resource "aws_s3_bucket" "this" {
  bucket = var.name
}

resource "aws_s3_bucket_versioning" "this" {
  bucket = aws_s3_bucket.this.id

  versioning_configuration {
    status = var.versioning ? "Enabled" : "Suspended"
  }
}
