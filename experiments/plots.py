"""Render plots 1-10 of the result analysis from the CSV tables in experiments/results/v1.

Run from the repository root:
    uv run --with-requirements experiments/requirements.txt python experiments/plots.py

Each plot is written as PNG (report) and SVG (docs) to experiments/results/v1/plots/.
The table/plot numbering is defined in experiments/README.md and
docs/12-academic/result-analysis-plan.md. Plot 9 (E5 performance) is drawn by
M3-11 once experiments/results/v1/e5/latency.csv exists; until then it is skipped.
"""

import csv
from collections import defaultdict
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402

RESULTS = Path(__file__).resolve().parent / "results" / "v1"
OUT = RESULTS / "plots"

# Reference categorical palette, fixed order (slots 1-3 validate for every pair).
SERIES = ["#2a78d6", "#eb6834", "#1baf7a"]
INK = "#0b0b0b"
INK_2 = "#52514e"
GRID = "#e4e3df"
SURFACE = "#fcfcfb"

plt.rcParams.update({
    "figure.facecolor": SURFACE,
    "axes.facecolor": SURFACE,
    "axes.edgecolor": INK_2,
    "axes.labelcolor": INK,
    "axes.titlecolor": INK,
    "axes.titlesize": 11,
    "axes.labelsize": 9,
    "axes.spines.top": False,
    "axes.spines.right": False,
    "axes.grid": True,
    "grid.color": GRID,
    "grid.linewidth": 0.6,
    "xtick.color": INK_2,
    "ytick.color": INK_2,
    "xtick.labelsize": 8,
    "ytick.labelsize": 8,
    "legend.fontsize": 8,
    "legend.frameon": False,
    "lines.linewidth": 2,
    "font.family": "DejaVu Sans",
    "svg.hashsalt": "smart-helpdesk-v1",  # stable SVG ids between runs
})


def rows(path):
    with open(RESULTS / path, newline="") as handle:
        return list(csv.DictReader(handle))


def save(fig, name):
    OUT.mkdir(parents=True, exist_ok=True)
    fig.tight_layout()
    fig.savefig(OUT / f"{name}.png", dpi=160, metadata={"Software": None})
    fig.savefig(OUT / f"{name}.svg", metadata={"Date": None, "Creator": None})
    plt.close(fig)
    print(f"wrote plots/{name}.png and .svg")


def policy_order(table):
    return list(dict.fromkeys(r["policy"] for r in table))


def plot_1_final_load():
    table = rows("e1/t2-final-load-per-agent.csv")
    policies = [c[: -len("_final_open")] for c in table[0] if c.endswith("_final_open")]
    agents = [r["agent"] for r in table]
    width = 0.8 / len(policies)
    fig, ax = plt.subplots(figsize=(7.5, 3.6))
    for i, policy in enumerate(policies):
        xs = [a + (i - (len(policies) - 1) / 2) * width for a in range(len(agents))]
        ax.bar(xs, [int(r[f"{policy}_final_open"]) for r in table], width * 0.9, color=SERIES[i], label=policy)
    for a, r in enumerate(table):
        ax.hlines(int(r["capacity"]), a - 0.45, a + 0.45, colors=INK, linewidth=1, linestyles="dashed",
                  label="capacity" if a == 0 else None)
    ax.set_xticks(range(len(agents)), agents)
    ax.set_ylabel("open tickets after the last arrival")
    ax.set_title("Plot 1 (E1): final open tickets per agent")
    ax.legend(ncols=4, loc="upper left")
    ax.set_ylim(0, max(max(int(r["capacity"]) for r in table), 14) + 3)
    save(fig, "plot-01-e1-final-load-per-agent")


def plot_over_time(column, ylabel, title, name):
    series = rows("e1/load-over-time.csv")
    fig, ax = plt.subplots(figsize=(7.5, 3.4))
    for i, policy in enumerate(policy_order(series)):
        points = [r for r in series if r["policy"] == policy]
        ax.plot([float(r["time_h"]) for r in points], [float(r[column]) for r in points],
                color=SERIES[i], linewidth=1.2, label=policy)
    ax.set_xlabel("simulated time (hours)")
    ax.set_ylabel(ylabel)
    ax.set_title(title)
    ax.legend(ncols=3, loc="lower right" if column.startswith("jain") else "upper left")
    save(fig, name)


def plot_4_precision_recall():
    table = rows("e2/t3-duplicate-thresholds.csv")
    fig, ax = plt.subplots(figsize=(5, 4))
    for i, variant in enumerate(dict.fromkeys(r["variant"] for r in table)):
        points = [r for r in table if r["variant"] == variant]
        ax.plot([float(r["recall"]) for r in points], [float(r["precision"]) for r in points],
                marker="o", markersize=4, color=SERIES[i], label=variant.replace("_", " + ", 1) if variant == "title_description" else "title only")
    ax.set_xlabel("recall")
    ax.set_ylabel("precision")
    ax.set_xlim(0, 1.02)
    ax.set_ylim(0, 1.05)
    ax.set_title("Plot 4 (E2): precision-recall, thresholds 0.10-0.90")
    ax.legend(loc="lower left")
    save(fig, "plot-04-e2-precision-recall")


def plot_5_f1():
    table = rows("e2/t3-duplicate-thresholds.csv")
    fig, ax = plt.subplots(figsize=(6, 3.6))
    for i, variant in enumerate(dict.fromkeys(r["variant"] for r in table)):
        points = [r for r in table if r["variant"] == variant]
        ax.plot([float(r["threshold"]) for r in points], [float(r["f1"]) for r in points],
                marker="o", markersize=4, color=SERIES[i], label="title + description" if variant == "title_description" else "title only")
    ax.axvline(0.35, color=INK_2, linewidth=1, linestyle="dashed", label="default threshold 0.35")
    ax.set_xlabel("threshold")
    ax.set_ylabel("F1 (all 300 pairs)")
    ax.set_ylim(0, 1)
    ax.set_title("Plot 5 (E2): F1 by threshold")
    ax.legend(loc="lower left")
    save(fig, "plot-05-e2-f1-by-threshold")


def plot_6_scores():
    table = rows("e2/pair-scores.csv")
    bins = [i / 20 for i in range(21)]
    fig, ax = plt.subplots(figsize=(6, 3.6))
    for i, label in enumerate(["duplicate", "non_duplicate"]):
        ax.hist([float(r["score_title_description"]) for r in table if r["label"] == label], bins=bins,
                color=SERIES[i], alpha=0.75, edgecolor=SURFACE, linewidth=1, label=label.replace("_", "-"))
    ax.axvline(0.35, color=INK_2, linewidth=1, linestyle="dashed", label="threshold 0.35")
    ax.set_xlabel("Jaccard score (title + description)")
    ax.set_ylabel("pairs")
    ax.set_title("Plot 6 (E2): score distribution by label")
    ax.legend()
    save(fig, "plot-06-e2-score-distribution")


def plot_7_ageing():
    table = rows("e3/ageing-curve.csv")
    fig, ax = plt.subplots(figsize=(6, 3.6))
    ax.plot([int(r["hours"]) for r in table], [float(r["score"]) for r in table], color=SERIES[0], label="score")
    for level, value in (("P2", 50), ("P3", 25)):
        ax.axhline(value, color=INK_2, linewidth=1, linestyle="dashed")
        ax.text(96, value + 0.8, f"{level} threshold {value}", ha="right", fontsize=8, color=INK_2)
    ax.set_xlabel("hours waited")
    ax.set_ylabel("priority score")
    ax.set_ylim(20, 60)
    ax.set_title("Plot 7 (E3): ageing of a ticket (impact 2, urgency 3, premium)")
    save(fig, "plot-07-e3-ageing-curve")


def plot_8_sensitivity():
    table = rows("e3/t7-priority-weight-sensitivity.csv")
    weights = list(dict.fromkeys(r["weight"] for r in table))
    fig, ax = plt.subplots(figsize=(6, 3.6))
    for i, delta in enumerate(["-0.1", "0.1"]):
        values = [int(next(r for r in table if r["weight"] == w and r["delta"] == delta)["changed"]) for w in weights]
        xs = [w + (i - 0.5) * 0.38 for w in range(len(weights))]
        bars = ax.bar(xs, values, 0.36, color=SERIES[i], label=f"weight {'-' if delta.startswith('-') else '+'}0.1")
        ax.bar_label(bars, fontsize=8, color=INK_2, padding=2)
    ax.set_xticks(range(len(weights)), weights)
    ax.set_ylabel("tickets changing level (of 200)")
    ax.set_title("Plot 8 (E3): level changes per weight change")
    ax.legend()
    save(fig, "plot-08-e3-weight-sensitivity")


def plot_9_performance():
    path = RESULTS / "e5" / "latency.csv"
    if not path.exists():
        print("skipped plot 9: E5 results (experiments/results/v1/e5/latency.csv) come from M3-11")
        return
    table = rows("e5/latency.csv")
    names = [r["endpoint"] for r in table]
    fig, ax = plt.subplots(figsize=(6.5, 3.6))
    ys = range(len(names))
    ax.barh([y - 0.2 for y in ys], [float(r["p50_ms"]) for r in table], 0.38, color=SERIES[0], label="p50")
    ax.barh([y + 0.2 for y in ys], [float(r["p95_ms"]) for r in table], 0.38, color=SERIES[1], label="p95")
    ax.set_yticks(list(ys), names)
    ax.set_xlabel("milliseconds")
    ax.set_title("Plot 9 (E5): API latency")
    ax.legend()
    save(fig, "plot-09-e5-latency")


def plot_10_history_latency():
    table = rows("e6/t11-report-latency.csv")
    names = [r["measure"] for r in table]
    ys = list(range(len(names)))[::-1]
    fig, ax = plt.subplots(figsize=(7.5, 3.8))
    ax.barh([y + 0.2 for y in ys], [float(r["p50_ms"]) for r in table], 0.38, color=SERIES[0], label="p50")
    ax.barh([y - 0.2 for y in ys], [float(r["p95_ms"]) for r in table], 0.38, color=SERIES[1], label="p95")
    ax.set_yticks(ys, names, fontsize=7)
    ax.set_xscale("log")
    ax.set_xlabel("milliseconds (log scale)")
    ax.set_title("Plot 10 (E6): report query and update latency")
    ax.legend(loc="lower right")
    save(fig, "plot-10-e6-latency")


if __name__ == "__main__":
    plot_1_final_load()
    plot_over_time("std_open", "std dev of open tickets per agent",
                   "Plot 2 (E1): load spread over time", "plot-02-e1-load-spread-over-time")
    plot_over_time("jain_utilisation", "Jain's index of utilisation",
                   "Plot 3 (E1): fairness over time", "plot-03-e1-jain-over-time")
    plot_4_precision_recall()
    plot_5_f1()
    plot_6_scores()
    plot_7_ageing()
    plot_8_sensitivity()
    plot_9_performance()
    plot_10_history_latency()
