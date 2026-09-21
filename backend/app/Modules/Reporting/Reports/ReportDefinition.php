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

    /** bar, line, stacked_area, heatmap, histogram or table */
    public function chart(): string;

    /** The entity the rows drill down to (`tickets`, `contacts`, …), or null when there is none. */
    public function drillDownTo(): ?string;

    public function run(ReportParameters $parameters): ReportResult;
}
