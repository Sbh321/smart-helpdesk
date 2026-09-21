#!/usr/bin/env bash
# Static checks for infra/tofu (docs/09-infrastructure/terraform.md): formatting, the provider-neutral
# contracts, and `tofu validate` of every provider module and environment. Needs no cloud credentials
# and creates nothing; `tofu init -backend=false` only downloads providers (cached in TF_PLUGIN_CACHE_DIR).
#   infra/scripts/tofu-check.sh            (mise exec -- infra/scripts/tofu-check.sh if tofu is not on PATH)
set -uo pipefail
cd "$(dirname "$0")/../tofu"
export TF_PLUGIN_CACHE_DIR="${TF_PLUGIN_CACHE_DIR:-$HOME/.cache/opentofu-plugins}"
export TF_IN_AUTOMATION=1
mkdir -p "$TF_PLUGIN_CACHE_DIR"
failures=0
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }

echo "tofu fmt"
tofu fmt -check -recursive . || fail "tofu fmt -recursive infra/tofu"

echo "contracts (every provider implements the same inputs and outputs)"
names() { grep -hoE "^$1 \"[a-z_0-9]+\"" "${@:2}" 2>/dev/null | awk '{print $2}' | tr -d '"' | sort -u; }
for module in modules/*/; do
  m="$(basename "$module")"
  want_out="$(names output "$module"outputs.tf)"
  for impl in providers/*/"$m"; do
    [[ -L $impl/variables.tf ]] || fail "$impl/variables.tf must link ../../../modules/$m/variables.tf"
    missing="$(comm -23 <(echo "$want_out") <(names output "$impl"/*.tf))"
    [[ -z $missing ]] || fail "$impl lacks outputs: $(echo "$missing" | tr '\n' ' ')"
  done
done

echo "tofu validate"
# A throwaway TF_DATA_DIR per folder: an operator's initialised .terraform (backend settings pointing at a
# real, encrypted state) is neither read nor rewritten by the check.
for dir in providers/*/*/ envs/*/; do
  data="$(mktemp -d)"
  if out="$(TF_DATA_DIR="$data" tofu -chdir="$dir" init -backend=false -input=false -no-color 2>&1)" &&
    out="$(TF_DATA_DIR="$data" tofu -chdir="$dir" validate -no-color 2>&1)"; then
    printf '  ok    %s\n' "${dir%/}"
  else
    fail "${dir%/}"
    printf '%s\n' "$out" | tail -15
  fi
  rm -rf "$data"
done

if [[ $failures -gt 0 ]]; then
  echo "${failures} check(s) failed"
  exit 1
fi
echo "All OpenTofu checks passed"
