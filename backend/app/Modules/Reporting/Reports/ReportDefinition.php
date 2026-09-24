<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/**
 * One catalogued report (ADR-0022 §4, docs/04-domain/reporting.md §Report catalogue). A definition
 * declares what may be asked; `ReportRunner` validates the request against it, so a user never
 * supplies SQL. Most reports extend `SqlReport`; a report with its own logic implements `run()`.
 */
interface ReportDefinition
{
    /** Catalogue id, lower case: `rpt-t01`. */
    public function key(): string;

    public function title(): string;

    public function description(): string;

    /** Catalogue group: tickets, contacts, agents, sla, media, administration. */
    public function group(): string;

    /**
     * Every permission the caller needs, beyond `reports.view`.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /** @return array<string, Dimension> */
    public function dimensions(): array;

    /** @return array<string, Measure> */
    public function measures(): array;

    /** @return array<string, Filter> */
    public function filters(): array;

    public function defaultDimension(): string;

    /** bar, stacked_bar, line, stacked_area, heatmap, histogram or table */
    public function chart(): string;

    /**
     * The measures the chart draws by default, when they are not simply the first measure and those of
     * its unit. A `stacked_bar` names the parts of a whole here (met, breached, running), so the whole
     * (`timers`) is never stacked on top of its own parts. Null: the SPA's default.
     *
     * @return list<string>|null
     */
    public function chartMeasures(): ?array;

    /** False for a report of the present (open tickets by age, running timers): no period, no comparison. */
    public function periodApplies(): bool;

    /** The entity the rows drill down to (`tickets`, `contacts`, …), or null when there is none. */
    public function drillDownTo(): ?string;

    public function run(ReportParameters $parameters): ReportResult;
}
