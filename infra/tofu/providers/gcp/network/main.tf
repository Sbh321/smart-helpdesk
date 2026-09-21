terraform {
  required_providers {
    google = { source = "hashicorp/google", version = "~> 8.3" }
  }
}

resource "google_compute_network" "this" {
  name                    = var.name
  auto_create_subnetworks = false
}

resource "google_compute_subnetwork" "this" {
  name          = "${var.name}-${var.region}"
  region        = var.region
  network       = google_compute_network.this.id
  ip_cidr_range = var.cidr
}
