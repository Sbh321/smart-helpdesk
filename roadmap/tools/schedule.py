#!/usr/bin/env python3
"""Compute calendar dates for the roadmap from roadmap/schedule.yaml.

Reads task IDs, sizes, tracks and dependencies from the milestone files
(lines like: ### `[ ]` M1-04 Title — L, critical ... **Depends:** M1-02, M1-03 ... **Track:** B)
and schedules them greedily per track respecting dependencies.
Standard library only (a tiny YAML subset parser is included).
"""
from __future__ import annotations
import datetime as dt, re, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SIZE_DAYS = {"XS": 0.25, "S": 0.5, "M": 1.0, "L": 1.5, "XL": 2.5}
WEEKDAYS = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"]

def load_yaml(path: Path) -> dict:
    data, key = {}, None
    for raw in path.read_text().splitlines():
        line = raw.split("#", 1)[0].rstrip()
        if not line.strip():
            continue
        if not line.startswith(" ") and ":" in line:
            key, _, val = line.partition(":")
            key, val = key.strip(), val.strip()
            if val.startswith("[") and val.endswith("]"):
                data[key] = [v.strip() for v in val[1:-1].split(",") if v.strip()]
            elif val == "":
                data[key] = []
            else:
                data[key] = val.strip('"')
        elif line.strip().startswith("- ") and key:
            data.setdefault(key, []).append(line.strip()[2:])
        elif line.startswith("  ") and ":" in line and key:
            k, _, v = line.strip().partition(":")
            if not isinstance(data.get(key), dict):
                data[key] = {}
            data[key][k.strip()] = v.strip()
    return data

def parse_tasks() -> list[dict]:
    tasks = []
    head = re.compile(r"^### `\[(.)\]` (M\d-\d+[a-z]?) (.+?) — (XS|S|M|L|XL)")
    for f in sorted(ROOT.glob("0[234]-*.md")):
        cur = None
        for line in f.read_text().splitlines():
            m = head.match(line)
            if m:
                cur = {"id": m[2], "title": m[3], "size": m[4], "status": m[1],
                       "deps": [], "track": "A", "critical": "critical" in line}
                tasks.append(cur)
            elif cur and line.startswith("- **Depends:**"):
                cur["deps"] = re.findall(r"M\d-\d+[a-z]?", line)
            elif cur and line.startswith("- **Track:**"):
                cur["track"] = line.split("**Track:**", 1)[1].strip()[:1]
    return tasks

def working_dates(cfg: dict):
    d = dt.date.fromisoformat(cfg["start_date"])
    days = {w.lower()[:3] for w in cfg["working_days"]}
    hol = {dt.date.fromisoformat(h) for h in cfg.get("holidays", []) if h}
    while True:
        if WEEKDAYS[d.weekday()] in days and d not in hol:
            yield d
        d += dt.timedelta(days=1)

def main() -> int:
    cfg = load_yaml(ROOT / "schedule.yaml")
    hours = float(cfg.get("hours_per_day", 8))
    tracks = int(cfg.get("parallel_tracks", 1))
    factor = 8.0 / hours  # effort days → calendar working days per track
    sizes = {k: float(v) for k, v in (cfg.get("size_days") or {}).items()} or SIZE_DAYS
    SIZE_DAYS.update(sizes)
    tasks = parse_tasks()
    by_id = {t["id"]: t for t in tasks}
    track_free = {}
    finish = {}
    pred = {}
    track_last = {}
    order = {t["id"]: i for i, t in enumerate(tasks)}
    children = {t["id"]: [] for t in tasks}
    for t in tasks:
        for d in t["deps"]:
            if d in children:
                children[d].append(t["id"])
    tail_cache = {}
    def tail(tid):  # longest chain of dependent work after this task (effort-days)
        if tid not in tail_cache:
            tail_cache[tid] = SIZE_DAYS[by_id[tid]["size"]] + max((tail(c) for c in children[tid]), default=0.0)
        return tail_cache[tid]
    pending = {t["id"] for t in tasks}
    while pending:
        best = None
        for tid in pending:
            t = by_id[tid]
            if any(d in pending for d in t["deps"] if d in by_id):
                continue
            tr = "ABCD".index(t["track"]) % tracks if t["track"] in "ABCD" else 0
            cands = [(track_free.get(tr, 0.0), track_last.get(tr, ""))] + [(finish[d], d) for d in t["deps"] if d in finish]
            start, p_ = max(cands)
            key = (round(start, 3), -tail(tid), order[tid])
            if best is None or key < best[0]:
                best = (key, tid, tr, start, p_)
        if best is None:
            print("dependency cycle among:", sorted(pending), file=sys.stderr)
            return 1
        _, tid, tr, start, p_ = best
        t = by_id[tid]
        end = start + SIZE_DAYS[t["size"]] * factor
        finish[tid], pred[tid] = end, p_
        track_free[tr], track_last[tr] = end, tid
        t["start"], t["end"] = start, end
        pending.remove(tid)
    total = max(finish.values()) if finish else 0
    total_with_buffer = total * (1 + float(cfg.get("buffer_ratio", 0)))
    dates = []
    gen = working_dates(cfg)
    while len(dates) < int(total_with_buffer) + 2:
        dates.append(next(gen))
    def day(x: float) -> dt.date:
        return dates[min(int(x), len(dates) - 1)]
    print(f"Effort: {sum(SIZE_DAYS[t['size']] for t in tasks):.1f} effort-days across {len(tasks)} tasks")
    print(f"Calendar: {total:.1f} working days on {tracks} track(s) at {hours:g} h/day; "
          f"{total_with_buffer:.1f} with buffer → finish {day(total_with_buffer)}")
    print()
    labels = {"x": "done", "~": "in progress", "-": "cut", "!": "blocked", " ": ""}
    print("| Task | Track | Size | Start | End | Critical | Status |")
    print("|---|---|---|---|---|---|---|")
    for t in tasks:
        print(f"| {t['id']} {t['title']} | {t['track']} | {t['size']} | {day(t['start'])} | "
              f"{day(max(t['end'] - 0.01, t['start']))} | {'yes' if t['critical'] else ''} | "
              f"{labels.get(t['status'], t['status'])} |")
    last = max(finish, key=finish.get)
    chain, cur = [], last
    while cur in by_id:
        chain.append(cur)
        cur = pred.get(cur, "")
    print("\nCritical chain (ends last):", " ← ".join(chain), f"[{cur}]" if cur else "")
    if "--ready" in sys.argv:
        done = {t["id"] for t in tasks if t["status"] in "x-"}
        ready = [t for t in tasks if t["status"] == " " and all(d in done for d in t["deps"])]
        print("\nReady now:")
        for t in ready:
            print(f"- {t['id']} {t['title']} (track {t['track']}, {t['size']})")
    missing = [d for t in tasks for d in t["deps"] if d not in by_id]
    if missing:
        print("\nUnknown dependencies:", sorted(set(missing)), file=sys.stderr)
        return 1
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
