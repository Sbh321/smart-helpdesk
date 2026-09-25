#!/usr/bin/env python3
"""
Draws the waterfall model of the report's development methodology (Figure 1.1) as SVG: the phases as a
descending cascade, each flowing into the next, with the main output of each phase beside it.

Usage: python3 tools/waterfall-diagram.py && node tools/render-svg.js university/figures/waterfall.svg
"""
import pathlib

OUT = pathlib.Path(__file__).resolve().parent.parent / "university" / "figures" / "waterfall.svg"
FONT = "Arial, 'Liberation Sans', 'DejaVu Sans', sans-serif"
PHASES = [
    ("Requirement Analysis", "requirements, use cases, feasibility", "#8ab4f8"),
    ("System Design", "UML models, database, architecture", "#81c995"),
    ("Implementation", "modules, algorithms, screens", "#fdd663"),
    ("Testing", "unit and system test cases", "#f6aea9"),
    ("Deployment and Maintenance", "live server, fixes, report", "#c58af9"),
]
BOX_W, BOX_H, STEP_X, STEP_Y = 290, 64, 120, 92
width = 40 + STEP_X * (len(PHASES) - 1) + BOX_W + 50
height = 30 + STEP_Y * (len(PHASES) - 1) + BOX_H + 60
svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}" '
       f'font-family="{FONT}" fill="#202124">',
       '<defs><marker id="arrow" markerWidth="12" markerHeight="10" refX="10" refY="5" orient="auto">'
       '<path d="M0,0 L12,5 L0,10 z" fill="#3c4043"/></marker></defs>',
       f'<rect width="{width}" height="{height}" fill="#ffffff"/>']
for i, (name, output, colour) in enumerate(PHASES):
    x, y = 40 + i * STEP_X, 30 + i * STEP_Y
    svg.append(f'<rect x="{x}" y="{y}" width="{BOX_W}" height="{BOX_H}" rx="8" fill="{colour}" stroke="#3c4043" stroke-width="1.5"/>')
    svg.append(f'<text x="{x + BOX_W / 2}" y="{y + 28}" text-anchor="middle" font-size="19" font-weight="bold">{name}</text>')
    svg.append(f'<text x="{x + BOX_W / 2}" y="{y + 51}" text-anchor="middle" font-size="16">{output}</text>')
    if i < len(PHASES) - 1:
        # out of the right side, then down onto the top of the next phase
        ex, ey = x + BOX_W, y + BOX_H / 2
        svg.append(f'<path d="M{ex},{ey} L{ex + 34},{ey} L{ex + 34},{y + STEP_Y - 3}" '
                   f'fill="none" stroke="#3c4043" stroke-width="2.2" marker-end="url(#arrow)"/>')
svg.append("</svg>")
OUT.write_text("\n".join(svg))
print(f"wrote {OUT.name} ({width}x{height})")
