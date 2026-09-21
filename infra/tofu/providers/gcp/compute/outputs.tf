output "id" { value = google_compute_instance.this.instance_id }
output "ipv4" { value = google_compute_address.this.address }
output "ipv6" { value = null }
output "private_ipv4" { value = google_compute_instance.this.network_interface[0].network_ip }
