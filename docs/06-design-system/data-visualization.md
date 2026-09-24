# Data visualisation

How the SPA draws numbers: which chart for which question, how a chart is labelled, and the rules every chart keeps. Pages: [principles](principles.md) · [themes](themes.md) · [components](components.md) · [page patterns](page-patterns.md) · [accessibility](accessibility.md). Introduced by roadmap [M4-09](../../roadmap/05-ui-revamp.md) (UX review findings [G3, G4, G6](ux-review.md)).

## Chart vocabulary

One component, `SeriesChart` (`components/shared/charts/series-chart.tsx`), draws every report and dashboard chart. The heatmap (`features/reports/components/heatmap-chart.tsx`) and the KPI sparkline are the only other chart surfaces.

| Kind | Question it answers | Drawn as | Declared by a report as |
|---|---|---|---|
| `line` | How did it change over time? | a line per measure over days, weeks or months | `line` (over a time grouping) |
| `area` | How did a level change over time? | overlapping areas (the measures are not parts of a whole) | `stacked_area` (over a time grouping) |
| `bar` | How do the categories compare? | horizontal bars, a ranking whose long names fit; several measures of one unit sit side by side (grouped) | `bar` (the default), and `line`/`stacked_area` grouped by a category |
| `bar`, over time | How much in each period? | upright columns in time order | any bar kind over a time grouping |
| `stacked_bar` | What is the whole made of? | the parts the report names, stacked; horizontal across categories, upright over time | `stacked_bar` with `chart_measures` (the parts) |
| `histogram` | How is it distributed? | upright bars without gaps over ordered buckets | `histogram` |
| `heatmap` | When does it happen? | a weekday × hour grid | `heatmap` (only with the `weekday_hour` grouping; bars otherwise) |
| sparkline | Which way is it heading? | a line without axes inside a KPI tile | a dashboard tile's `trend_series` |
| none | The rows are the answer | the table only | `table` |

The report says which kind fits its data (`chart`, and for a stacked bar `chart_measures`); the SPA only chooses the orientation from the grouping (`features/reports/chart-choice.ts`). Stacked bars are declared where the measures are disjoint parts of a whole, and a catalogue test enforces that the parts exist and share one unit: SLA compliance (met, breached, still running), assignment behaviour (automatic, manual, reassignments) and duplicates (accepted, dismissed, not decided). A chart is never added where the API returns no series for it: the comparison of a report is a pair of totals, not a previous-period series, so the tiles show the change in words and the chart shows only the current period.

## Labels

- **Every chart has a legend**, one series too: the legend is what says which measure the marks stand for. It follows the series order (the order of the stack), never the alphabet.
- **Axis dates in the app's format.** Rows arrive keyed by calendar date (`2026-08-25`, `2026-09`); `lib/format/dimension-labels.ts` turns them into "25 Aug" on the axis and "Tue 25 Aug 2026" in the tooltip, table and drill-down title; weeks read "Week of 24 Aug"; months "Sep 2026". The keys are dates the API already bucketed in the workspace zone, so no zone conversion is applied (none may move a day).
- **The empty bucket is named after its dimension**: "No team", "No agent", not the API's generic "None".
- **Units on the value axis and in the tooltip** come from the measure (`lib/format/measure.ts`: counts, "3h 20m", "82.7%"); one chart has one unit, so one value axis, never two.
- **Tabular figures** on axes, tiles and table cells, so digits line up.
- **"Chart shows" names what it shows**: its default option lists the default measures ("Default: Met, Breached, Still running").

## Colour

Series colours are `--chart-1..6` in series order ([themes §Charts](themes.md#charts)); they re-resolve on theme change, so dark mode needs no re-render. Colour never carries meaning alone: the legend, the tooltip and the table name every series. No colour says good or bad on a KPI tile, because that depends on the measure.

## Accessibility

- The chart surface has `accessibilityLayer` (keyboard walk of the points) and a title.
- The same numbers are always in the page as a table: the report page's data table, the dashboard chart's own table (visually hidden, one click to show, printed).
- Sparklines are `aria-hidden`: the tile states its value and change in words.
- Axe runs on three representative reports (line, stacked bar, a report of the present) in both themes (`features/reports/reports.browser.test.tsx`).

## Report toolbar

Every report has the same toolbar: period (presets or dates), comparison, group-by, then the report's own filters, and below them the **parameters in force as removable chips** (period when not the default, comparison, grouping when not the default, each filter with its values in words). Removing a chip is the same navigation as resetting its control. A report of the present (`period_applies: false`: ageing, at-risk) has no period or comparison controls and says so.
