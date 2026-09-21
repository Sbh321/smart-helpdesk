terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 6.65" }
  }
}

data "aws_subnet" "this" {
  id = var.network_id
}

resource "aws_security_group" "this" {
  name   = var.name
  vpc_id = data.aws_subnet.this.vpc_id

  ingress {
    protocol    = "tcp"
    from_port   = 22
    to_port     = 22
    cidr_blocks = var.ssh_cidrs
  }

  dynamic "ingress" {
    for_each = { http = ["tcp", 80], https = ["tcp", 443], h3 = ["udp", 443], smtp = ["tcp", 25] }
    content {
      protocol         = ingress.value[0]
      from_port        = ingress.value[1]
      to_port          = ingress.value[1]
      cidr_blocks      = ["0.0.0.0/0"]
      ipv6_cidr_blocks = ["::/0"]
    }
  }

  egress {
    protocol         = "-1"
    from_port        = 0
    to_port          = 0
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }
}

# Attach to the primary network interface of each instance.
data "aws_instance" "this" {
  count       = length(var.compute_ids)
  instance_id = var.compute_ids[count.index]
}

resource "aws_network_interface_sg_attachment" "this" {
  count                = length(var.compute_ids)
  security_group_id    = aws_security_group.this.id
  network_interface_id = data.aws_instance.this[count.index].network_interface_id
}
