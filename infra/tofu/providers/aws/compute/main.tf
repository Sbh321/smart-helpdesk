terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 6.65" }
  }
}

# var.region is taken from the provider configuration; image is an AMI id (Debian 13: see
# https://wiki.debian.org/Cloud/AmazonEC2Image), ssh_key_ids[0] an EC2 key pair name.
resource "aws_instance" "this" {
  ami                         = var.image
  instance_type               = var.size # t3.medium
  subnet_id                   = var.network_id
  key_name                    = var.ssh_key_ids[0]
  user_data                   = var.user_data
  associate_public_ip_address = true

  root_block_device {
    volume_size = var.volume_gb
    volume_type = "gp3"
    encrypted   = true
  }

  metadata_options {
    http_tokens = "required"
  }

  tags = { Name = var.name, app = "smart-helpdesk" }
}

# A stable public address for DNS and the mail PTR record.
resource "aws_eip" "this" {
  instance = aws_instance.this.id
  domain   = "vpc"
}
