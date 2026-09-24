#!/usr/bin/env bash
# Build the Project III report DOCX. Usage: ./build.sh [university] [--final]
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules/docx ] || npm install --no-audit --no-fund
# The report is the only target; "university" is accepted for the older invocation.
if [ "${1:-}" = university ]; then shift; fi
node tools/build-docx.js university "$@"
