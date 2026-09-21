terraform {
  required_providers {
    digitalocean = { source = "digitalocean/digitalocean", version = "~> 2.100" }
  }
}

resource "digitalocean_spaces_bucket" "this" {
  name   = var.name
  region = var.region

  versioning {
    enabled = var.versioning
  }
}

# A key limited to this bucket (the provider's own Spaces key only creates the bucket).
resource "digitalocean_spaces_key" "app" {
  name = "${var.name}-app"

  grant {
    bucket     = digitalocean_spaces_bucket.this.name
    permission = "readwrite"
  }
}
