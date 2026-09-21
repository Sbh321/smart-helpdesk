# Contract outputs. Implementations return real values; this contract folder only documents them.
output "network_id" {
  description = "What compute attaches to: VPC (DigitalOcean), network (Hetzner), subnet (AWS, GCP), empty (none)."
  value       = null
}

output "subnet_cidr" {
  description = "Private range actually assigned."
  value       = null
}
