#!/usr/bin/env python3
"""
Draws the project Gantt chart (Figure 3.3) as SVG with a date axis: months across the top, the first day
of each week below them, and one bar per phase from its start to its end date, matching Table 3.5.

Usage: python3 tools/gantt-diagram.py && node tools/render-svg.js university/figures/gantt.svg
"""
import datetime as dt
import pathlib

OUT = pathlib.Path(__file__).resolve().parent.parent / "university" / "figures" / "gantt.svg"
FONT = "Arial, 'Liberation Sans', 'DejaVu Sans', sans-serif"
START, END = dt.date(2026, 6, 16), dt.date(2026, 9, 15)
# (phase, first day, last day, colour) as in Table 3.5
PHASES = [
    ("Requirement analysis", dt.date(2026, 6, 16), dt.date(2026, 6, 29), "#8ab4f8"),
    ("System design", dt.date(2026, 6, 30), dt.date(2026, 7, 13), "#81c995"),
    ("Implementation", dt.date(2026, 7, 14), dt.date(2026, 8, 24), "#fdd663"),
    ("Testing", dt.date(2026, 8, 25), dt.date(2026, 9, 7), "#f6aea9"),
    ("Deployment and maintenance", dt.date(2026, 9, 8), dt.date(2026, 9, 15), "#c58af9"),
    ("Documentation and report", START, END, "#dadce0"),
]

DAYS = (END - START).days + 1
LEFT, TOP, ROW, DAY_W = 290, 74, 40, 6.2
width = int(LEFT + DAYS * DAY_W + 24)
height = TOP + len(PHASES) * ROW + 20
x_of = lambda day: LEFT + (day - START).days * DAY_W

svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}" '
       f'font-family="{FONT}" fill="#202124">',
       f'<rect width="{width}" height="{height}" fill="#ffffff"/>']
# week columns (starting on the project's first day) with the day of the month on top
day, n = START, 0
while day <= END:
    x = x_of(day)
    w = min(7, (END - day).days + 1) * DAY_W
    svg.append(f'<rect x="{x:.1f}" y="{TOP - 6}" width="{w:.1f}" height="{len(PHASES) * ROW + 12}" '
               f'fill="{"#f8f9fa" if n % 2 == 0 else "#ffffff"}" stroke="#dadce0" stroke-width="1"/>')
    svg.append(f'<text x="{x + 3:.1f}" y="{TOP - 14}" font-size="15">{day.day}</text>')
    day += dt.timedelta(days=7)
    n += 1
# month labels over the days they cover
month = START.replace(day=1)
while month <= END:
    first = max(month, START)
    nxt = (month.replace(day=28) + dt.timedelta(days=4)).replace(day=1)
    last = min(nxt - dt.timedelta(days=1), END)
    cx = (x_of(first) + x_of(last) + DAY_W) / 2
    svg.append(f'<text x="{cx:.1f}" y="{TOP - 42}" text-anchor="middle" font-size="17" font-weight="bold">{month.strftime("%B %Y")}</text>')
    svg.append(f'<line x1="{x_of(first):.1f}" y1="{TOP - 36}" x2="{x_of(last) + DAY_W:.1f}" y2="{TOP - 36}" stroke="#80868b" stroke-width="1.5"/>')
    month = nxt
for i, (name, first, last, colour) in enumerate(PHASES):
    y = TOP + i * ROW
    svg.append(f'<text x="{LEFT - 12}" y="{y + ROW / 2 + 6}" text-anchor="end" font-size="18">{name}</text>')
    x, w = x_of(first) + 1, (last - first).days * DAY_W + DAY_W - 2
    svg.append(f'<rect x="{x:.1f}" y="{y + 7}" width="{w:.1f}" height="{ROW - 14}" rx="5" fill="{colour}" stroke="#3c4043" stroke-width="1"/>')
svg.append("</svg>")
OUT.write_text("\n".join(svg))
print(f"wrote {OUT.name} ({width}x{height})")
