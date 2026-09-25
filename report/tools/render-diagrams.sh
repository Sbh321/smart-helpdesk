#!/usr/bin/env bash
# Renders the report's own diagrams for portrait A4 (about 10 pt text when printed):
# Mermaid sources in university/figures/*.mmd with the shared report config, the use case diagrams and
# the Gantt chart drawn as SVG, then trims the white margin around every result.
set -euo pipefail
cd "$(dirname "$0")/.."
FIG=university/figures
for mmd in "$FIG"/*.mmd; do
  node_modules/.bin/mmdc -p puppeteer.json -c "$FIG/mermaid.config.json" -i "$mmd" -o "${mmd%.mmd}.png" -b white -s 2 >/dev/null
done
python3 tools/usecase-diagrams.py >/dev/null
python3 tools/gantt-diagram.py >/dev/null
python3 tools/waterfall-diagram.py >/dev/null
node tools/render-svg.js "$FIG/uc-01.svg" "$FIG/uc-02.svg" "$FIG/gantt.svg" "$FIG/waterfall.svg" >/dev/null
python3 - "$FIG" <<'PY'
import glob, sys
from PIL import Image, ImageChops
names = [p[:-4] for p in glob.glob(f"{sys.argv[1]}/*.mmd")] + [f"{sys.argv[1]}/{n}" for n in ("uc-01", "uc-02", "gantt", "waterfall")]
for base in names:
    im = Image.open(base + ".png").convert("RGB")
    box = ImageChops.difference(im, Image.new("RGB", im.size, "white")).getbbox()
    if box:
        pad = 16
        im = im.crop((max(0, box[0] - pad), max(0, box[1] - pad), min(im.width, box[2] + pad), min(im.height, box[3] + pad)))
        im.save(base + ".png", optimize=True)
    w, h = im.size[0] / 2, im.size[1] / 2
    scale = min(600 / w, 816 / h, 1)
    print(f"{base.split('/')[-1]:18} {w:5.0f} x {h:5.0f}  prints at ~{20 * scale * 0.75:4.1f} pt")
PY
