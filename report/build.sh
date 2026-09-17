#!/usr/bin/env bash
# Build report DOCX files. Usage: ./build.sh [university|internship|all] [--final]
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules/docx ] || npm install --no-audit --no-fund
target="${1:-all}"; shift || true
if [ "$target" = all ]; then
  node tools/build-docx.js university "$@"
  node tools/build-docx.js internship "$@"
else
  node tools/build-docx.js "$target" "$@"
fi
