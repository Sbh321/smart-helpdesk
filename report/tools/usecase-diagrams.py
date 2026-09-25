#!/usr/bin/env python3
"""
Draws the report's UML use case diagrams as SVG (Mermaid has no use case diagram): actors as stick
figures outside a system boundary, use cases as ellipses in a grid inside it, associations as lines and
<<include>> as dashed arrows. Sized for a portrait A4 page, so the text prints at about 10 pt.

Usage: python3 tools/usecase-diagrams.py   (writes university/figures/uc-01.svg and uc-02.svg)
       node tools/render-svg.js university/figures/uc-01.svg ...   (PNG at twice the size)
"""
import math
import pathlib

OUT = pathlib.Path(__file__).resolve().parent.parent / "university" / "figures"
FONT = "Arial, 'Liberation Sans', 'DejaVu Sans', sans-serif"
W = 900
EW, EH = 190, 66            # ellipse size
COLS = [340, 590]           # ellipse centre x of the two columns inside the boundary
BOX = (230, 700)            # system boundary x range
ROW0, ROWH = 110, 86        # first row centre y and row height

STYLE = {
    "core": ("#e8f0fe", "#1a56db"),
    "auto": ("#e6f4ea", "#137333"),
    "admin": ("#fef7e0", "#b06000"),
    "report": ("#f3e8fd", "#7627bb"),
}


def wrap(text, width=19):
    words, lines, line = text.split(), [], ""
    for word in words:
        if len(line) + len(word) + 1 > width and line:
            lines.append(line)
            line = word
        else:
            line = f"{line} {word}".strip()
    lines.append(line)
    return lines


def ellipse_edge(cx, cy, tx, ty):
    """Point on the ellipse boundary in the direction of (tx, ty)."""
    a, b = EW / 2, EH / 2
    dx, dy = tx - cx, ty - cy
    if dx == 0 and dy == 0:
        return cx, cy
    t = 1 / math.sqrt((dx * dx) / (a * a) + (dy * dy) / (b * b))
    return cx + dx * t, cy + dy * t


def actor(x, y, label):
    lines = wrap(label, 16)
    parts = [
        f'<circle cx="{x}" cy="{y - 38}" r="11" fill="#fff" stroke="#202124" stroke-width="2"/>',
        f'<line x1="{x}" y1="{y - 27}" x2="{x}" y2="{y + 3}" stroke="#202124" stroke-width="2"/>',
        f'<line x1="{x - 18}" y1="{y - 16}" x2="{x + 18}" y2="{y - 16}" stroke="#202124" stroke-width="2"/>',
        f'<line x1="{x}" y1="{y + 3}" x2="{x - 14}" y2="{y + 24}" stroke="#202124" stroke-width="2"/>',
        f'<line x1="{x}" y1="{y + 3}" x2="{x + 14}" y2="{y + 24}" stroke="#202124" stroke-width="2"/>',
    ]
    for i, text in enumerate(lines):
        parts.append(f'<text x="{x}" y="{y + 44 + i * 20}" text-anchor="middle" font-size="17" font-weight="bold">{text}</text>')
    return "\n".join(parts)


def diagram(name, title, cases, actors, links, includes):
    rows = max(r for (_, r, _, _) in cases.values()) + 1
    height = ROW0 + rows * ROWH + 50
    centre = {k: (COLS[c], ROW0 + r * ROWH) for k, (c, r, _, _) in cases.items()}
    svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{height}" viewBox="0 0 {W} {height}" '
           f'font-family="{FONT}" fill="#202124">',
           '<defs><marker id="arrow" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto">'
           '<path d="M0,0 L10,4 L0,8" fill="none" stroke="#5f6368" stroke-width="1.5"/></marker></defs>',
           f'<rect x="0" y="0" width="{W}" height="{height}" fill="#ffffff"/>',
           f'<rect x="{BOX[0]}" y="30" width="{BOX[1] - BOX[0]}" height="{height - 80}" rx="14" fill="#f8f9fa" stroke="#5f6368" stroke-width="1.5"/>',
           f'<text x="{(BOX[0] + BOX[1]) / 2}" y="56" text-anchor="middle" font-size="18" font-weight="bold">{title}</text>']
    # associations first, so they run under the shapes
    for actor_key, case_key in links:
        ax, ay = actors[actor_key][0], actors[actor_key][1] - 10
        cx, cy = centre[case_key]
        ex, ey = ellipse_edge(cx, cy, ax, ay)
        svg.append(f'<line x1="{ax + (22 if ax < cx else -22)}" y1="{ay}" x2="{ex:.1f}" y2="{ey:.1f}" stroke="#80868b" stroke-width="1.4"/>')
    for src, dst in includes:
        (sx, sy), (dx, dy) = centre[src], centre[dst]
        x1, y1 = ellipse_edge(sx, sy, dx, dy)
        x2, y2 = ellipse_edge(dx, dy, sx, sy)
        svg.append(f'<line x1="{x1:.1f}" y1="{y1:.1f}" x2="{x2:.1f}" y2="{y2:.1f}" stroke="#5f6368" stroke-width="1.4" '
                   f'stroke-dasharray="6 4" marker-end="url(#arrow)"/>')
    if includes:
        sx, sy = centre[includes[0][0]]
        svg.append(f'<text x="{sx + EW / 2 + 8}" y="{sy - 14}" font-size="15" fill="#5f6368">«include»</text>')
    for key, (c, r, label, kind) in cases.items():
        x, y = centre[key]
        fill, stroke = STYLE[kind]
        svg.append(f'<ellipse cx="{x}" cy="{y}" rx="{EW / 2}" ry="{EH / 2}" fill="{fill}" stroke="{stroke}" stroke-width="2"/>')
        lines = wrap(label)
        for i, text in enumerate(lines):
            dy = (i - (len(lines) - 1) / 2) * 20 + 6
            svg.append(f'<text x="{x}" y="{y + dy:.1f}" text-anchor="middle" font-size="18">{text}</text>')
    for key, (x, y, label) in actors.items():
        svg.append(actor(x, y, label))
    svg.append("</svg>")
    (OUT / f"{name}.svg").write_text("\n".join(svg))


# Figure 3.1: ticket handling and automation
diagram(
    "uc-01", "Ticket handling",
    cases={
        "create": (0, 0, "Create ticket", "core"),
        "view": (0, 1, "View and filter tickets", "core"),
        "reply": (0, 2, "Reply or add internal note", "core"),
        "attach": (0, 3, "Attach file", "core"),
        "status": (0, 4, "Change status", "core"),
        "dups": (0, 5, "Review duplicates", "core"),
        "history": (0, 6, "View history and SLA", "core"),
        "score": (1, 0, "Score priority", "auto"),
        "assign": (1, 1, "Auto-assign agent", "auto"),
        "detect": (1, 2, "Detect duplicates", "auto"),
        "reassign": (1, 4, "Assign or reassign", "core"),
        "override": (1, 5, "Override priority", "core"),
        "sla": (1, 6, "Evaluate SLA and notify", "auto"),
    },
    actors={
        "contact": (100, 150, "Contact (email) or external system"),
        "agent": (100, 430, "Support Agent"),
        "manager": (800, 490, "Support Manager"),
        "scheduler": (800, 660, "Scheduler"),
    },
    links=[("contact", "create"), ("contact", "reply"), ("agent", "create"), ("agent", "view"), ("agent", "reply"),
           ("agent", "attach"), ("agent", "status"), ("agent", "dups"), ("agent", "history"),
           ("manager", "reassign"), ("manager", "override"), ("scheduler", "sla")],
    includes=[("create", "score"), ("create", "assign"), ("create", "detect")],
)

# Figure 3.2: administration, contacts and reporting
diagram(
    "uc-02", "Administration and reporting",
    cases={
        "tenants": (0, 0, "Manage workspaces", "admin"),
        "users": (0, 1, "Manage users and roles", "admin"),
        "teams": (0, 2, "Manage teams, skills and shifts", "admin"),
        "sla": (0, 3, "Manage SLA policies", "admin"),
        "auto": (0, 4, "Configure automation", "admin"),
        "clients": (0, 5, "Manage API clients and webhooks", "admin"),
        "contacts": (1, 0, "Manage contacts and organisations", "core"),
        "media": (1, 1, "Manage media library", "core"),
        "dashboard": (1, 2, "View dashboard", "report"),
        "reports": (1, 3, "Run and export reports", "report"),
        "history": (1, 4, "View record history", "report"),
        "inbound": (1, 5, "Process inbound email", "auto"),
    },
    actors={
        "platform": (100, 110, "Platform Super Admin"),
        "admin": (100, 370, "Tenant Admin"),
        "developer": (100, 570, "Developer"),
        "agent": (800, 150, "Support Agent"),
        "manager": (800, 370, "Support Manager"),
        "scheduler": (800, 570, "Scheduler"),
    },
    links=[("platform", "tenants"), ("admin", "users"), ("admin", "teams"), ("admin", "sla"), ("admin", "auto"),
           ("developer", "clients"), ("agent", "contacts"), ("agent", "media"), ("manager", "dashboard"),
           ("manager", "reports"), ("manager", "history"), ("scheduler", "inbound")],
    includes=[],
)
print("wrote uc-01.svg and uc-02.svg")
