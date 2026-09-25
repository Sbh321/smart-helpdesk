#!/usr/bin/env python3
"""
Reads the report laid out by LibreOffice (pdftotext -layout output, pages separated by form feeds) and
writes university/pages.json: the printed page number (roman or arabic, from the footer) of every
heading and caption the builder listed in .build/entries.json. Headings are matched as whole lines, so
the contents pages that repeat them with dot leaders never match; body text is searched only after the
lists, in document order.

Usage: python3 tools/measure-pages.py <report.txt>
"""
import json
import pathlib
import re
import sys

root = pathlib.Path(__file__).resolve().parent.parent
entries = json.loads((root / ".build" / "entries.json").read_text())
pages = pathlib.Path(sys.argv[1]).read_text().split("\f")

norm = lambda s: re.sub(r"\s+", " ", s).strip()
lines = [[norm(l) for l in p.splitlines() if l.strip()] for p in pages]


def label(i):
    last = lines[i][-1] if lines[i] else ""
    return last if re.fullmatch(r"[ivxlc]+|\d+", last) else None


def find(text, start, whole=True):
    t = norm(text)
    for i in range(start, len(lines)):
        for l in lines[i]:
            if (l == t) if whole else (l.startswith(t[: min(len(t), 60)]) and "...." not in l):
                return i
    return None


result, missing = {}, []
for t in entries["front"]:
    i = find(t, 0)
    if i is not None:
        result[t] = label(i)
body_start = (find("List of Abbreviations", 0) or 0) + 1
cursor = body_start
for t in entries["headings"]:
    i = find(t, cursor)
    if i is None:
        missing.append(t)
        continue
    result[t], cursor = label(i), i
cursor = body_start
for t in entries["captions"]:
    i = find(t, body_start, whole=False)
    if i is None:
        missing.append(t)
        continue
    result[t] = label(i)
(root / "university" / "pages.json").write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
print(f"pages: {len(pages) - 1}; located {len(result)} entries; missing {len(missing)}")
for t in missing:
    print("  missing:", t)
