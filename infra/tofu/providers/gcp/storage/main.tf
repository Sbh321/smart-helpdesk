terraform {
  required_providers {
    google = { source = "hashicorp/google", version = "~> 8.3" }
  }
}

# GCS through its S3-interoperability API: a service account with an HMAC key limited to the bucket.
resource "google_storage_bucket" "this" {
  name                        = var.name
  location                    = upper(var.region)
  uniform_bucket_level_access = true
  public_access_prevention    = "enforced"

  versioning {
    enabled = var.versioning
  }
}

resource "google_service_account" "app" {
  account_id   = substr(replace("${var.name}-app", "_", "-"), 0, 30)
  display_name = "Smart Helpdesk object storage"
}

resource "google_storage_bucket_iam_member" "app" {
  bucket = google_storage_bucket.this.name
  role   = "roles/storage.objectAdmin"
  member = "serviceAccount:${google_service_account.app.email}"
}

resource "google_storage_hmac_key" "app" {
  service_account_email = google_service_account.app.email
}
