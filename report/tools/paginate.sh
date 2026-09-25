#!/usr/bin/env bash
# Builds the report, lays it out with LibreOffice (tools/lo, Docker), records the page of every heading and
# caption in university/pages.json and builds again with the contents and lists filled in; repeats until the
# numbers stop changing. Prints the page count.
#   docker build -t shp-report-lo tools/lo   (once)
#   ./tools/paginate.sh [--final]
set -euo pipefail
cd "$(dirname "$0")/.."
out_dir=$(node -e "const m=require('./university/metadata.json');console.log(require('path').resolve(m.output_dir||'out'))")
name=$(node -e "console.log(require('./university/metadata.json').output||'university')")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
for pass in 1 2 3 4; do
  node tools/build-docx.js university "$@" >/dev/null
  cp "$out_dir/$name.docx" "$work/report.docx"
  docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$work:/work" shp-report-lo sh -c \
    'soffice -env:UserInstallation=file:///tmp/lo-profile --headless --convert-to pdf --outdir /work /work/report.docx >/dev/null 2>&1 && pdftotext -layout /work/report.pdf /work/report.txt'
  before=$(cat university/pages.json 2>/dev/null || true)
  python3 tools/measure-pages.py "$work/report.txt"
  [[ "$before" == "$(cat university/pages.json)" ]] && break
done
node tools/build-docx.js university "$@"
cp "$work/report.pdf" .build/layout-check.pdf 2>/dev/null || true
