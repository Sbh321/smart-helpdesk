# Instances attach to the subnetwork; the firewall derives the network from it.
output "network_id" { value = google_compute_subnetwork.this.self_link }
output "subnet_cidr" { value = google_compute_subnetwork.this.ip_cidr_range }
