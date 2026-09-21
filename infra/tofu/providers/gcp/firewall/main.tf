terraform {
  required_providers {
    google = { source = "hashicorp/google", version = "~> 8.3" }
  }
}

# GCP firewall rules target network tags; compute tags its instance "smart-helpdesk", so compute_ids
# is part of the contract only. Outbound port 25 is blocked by GCP: use MAIL_RELAY_HOST.
data "google_compute_subnetwork" "this" {
  self_link = var.network_id
}

resource "google_compute_firewall" "ssh" {
  name          = "${var.name}-ssh"
  network       = data.google_compute_subnetwork.this.network
  source_ranges = var.ssh_cidrs
  target_tags   = ["smart-helpdesk"]

  allow {
    protocol = "tcp"
    ports    = ["22"]
  }
}

resource "google_compute_firewall" "web" {
  name          = "${var.name}-web"
  network       = data.google_compute_subnetwork.this.network
  source_ranges = ["0.0.0.0/0"]
  target_tags   = ["smart-helpdesk"]

  allow {
    protocol = "tcp"
    ports    = ["25", "80", "443"]
  }

  allow {
    protocol = "udp"
    ports    = ["443"]
  }
}
