terraform {
  required_providers {
    google = { source = "hashicorp/google", version = "~> 8.3" }
  }
}

# user_data needs a cloud-init image: GCP's Debian images do not run cloud-init, Ubuntu's do
# (image = "ubuntu-os-cloud/ubuntu-2404-lts-amd64"). ssh_key_ids are "user:ssh-ed25519 AAAA…" lines.
resource "google_compute_address" "this" {
  name   = var.name
  region = var.region
}

resource "google_compute_instance" "this" {
  name         = var.name
  machine_type = var.size # e2-medium
  zone         = "${var.region}-a"
  tags         = ["smart-helpdesk"]

  boot_disk {
    initialize_params {
      image = var.image
      size  = var.volume_gb
    }
  }

  network_interface {
    subnetwork = var.network_id

    access_config {
      nat_ip = google_compute_address.this.address
    }
  }

  metadata = {
    user-data = var.user_data
    ssh-keys  = join("\n", var.ssh_key_ids)
  }
}
