<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * A report that is one grouped SQL query over a source (ADR-0022 §4): the definition supplies the FROM
 * clause, the tenant and period columns and allow-listed SQL fragments; the parameters only choose among
 * them and bind values. Every query is scoped to the workspace and the period, capped at ROW_CAP groups.
 */
abstract class SqlReport implements ReportDefinition
{
    public const int ROW_CAP = 1000;

    /** Comparisons with fewer records in the previous period are suppressed (history doc §6). */
    public const int MIN_COMPARISON_RECORDS = 5;

    /** FROM clause with aliases, for example `report_ticket_facts f`. */
    abstract protected function source(): string;

    /** The tenant column of the source's main table, for example `f.tenant_id`. */
    abstract protected function tenantColumn(): string;

    /** The timestamp the period applies to, for example `f.created_at`. */
    abstract protected function periodColumn(): string;

    /**
     * The FROM clause for one request: `source()` unless the report varies it by dimension. It may hold
     * `?` placeholders, bound by `sourceBindings()`, and `{tz}` for the workspace time zone.
     */
    protected function sourceFor(ReportParameters $parameters): string
    {
        return $this->source();
    }

    /**
     * Values for the `?` placeholders of the source, for the period asked (the previous one for the
     * comparison). A source that filters its own tables binds the tenant id here too.
     *
     * @return list<mixed>
     */
    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [];
    }

    /** False for a report about "now" (ageing, at-risk): no period clause and no comparison. */
    protected function periodApplies(): bool
    {
        return true;
    }

    /** ORDER BY of the rows: the key unless the report ranks them (an aggregate expression). */
    protected function orderBy(): string
    {
        return '1';
    }

    /** The application's now (the Clock), in UTC, for open intervals and "now" reports. */
    protected function now(): CarbonImmutable
    {
        return app(Clock::class)->now()->utc();
    }

    /** The end of the period, or now when the period has not ended yet: where open durations stop. */
    protected function until(DateTimeInterface $to): CarbonImmutable
    {
        $end = CarbonImmutable::instance($to)->utc();

        return $end->lessThan($this->now()) ? $end : $this->now();
    }

    /** What a drill-down row is: the id expression of `drillDownTo()` records. */
    protected function recordKey(): ?string
    {
        return null;
    }

    public function drillDownTo(): ?string
    {
        return null;
    }

    public function chart(): string
    {
        return 'bar';
    }

    public function filters(): array
    {
        return [];
    }

    public function description(): string
    {
        return '';
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $dimension = $this->dimensions()[$parameters->dimension];
        [$where, $bindings] = $this->where($parameters, $parameters->from, $parameters->to);
        $measures = $this->measureSql($parameters);

        $rows = DB::select(
            $this->withTimezone(sprintf('SELECT %s AS k, %s FROM %s WHERE %s GROUP BY 1 ORDER BY %s LIMIT %d',
                $dimension->sql, $measures, $this->sourceFor($parameters), $where, $this->orderBy(), self::ROW_CAP + 1), $parameters),
            $bindings,
        );
        $truncated = count($rows) > self::ROW_CAP;
        $rows = array_slice($rows, 0, self::ROW_CAP);

        $totals = $this->totals($parameters, $parameters->from, $parameters->to);
        $previous = null;
        if ($parameters->compare && $this->periodApplies()) {
            $previous = $this->totals($parameters, $parameters->previousFrom(), $parameters->from);
            if (($previous['_records'] ?? 0) < self::MIN_COMPARISON_RECORDS) {
                $previous = null;
            }
        }
        unset($totals['_records']);
        if ($previous !== null) {
            unset($previous['_records']);
        }

        $labels = app(DimensionLabels::class)->for($dimension->labels, array_map(fn (object $row): string => (string) ($row->k ?? '-'), $rows));

        return new ReportResult(
            array_map(function (object $row) use ($parameters, $labels): array {
                $key = $row->k === null ? '-' : (string) $row->k;

                return ['key' => $key, 'label' => $labels[$key] ?? $key, 'values' => $this->values($row, $parameters->measures)];
            }, $rows),
            $totals,
            $previous,
            $truncated,
        );
    }

    /**
     * The ids behind one number (drill-down), in the report's period and filters.
     *
     * @return array{ids: list<string>, total: int}
     */
    public function records(ReportParameters $parameters, ?string $dimensionKey, int $page, int $perPage): array
    {
        $key = $this->recordKey();
        if ($key === null) {
            return ['ids' => [], 'total' => 0];
        }

        [$where, $bindings] = $this->where($parameters, $parameters->from, $parameters->to);
        if ($dimensionKey !== null) {
            $expression = $this->dimensions()[$parameters->dimension]->sql;
            if ($dimensionKey === '-') {
                $where .= " AND ({$expression}) IS NULL";
            } else {
                $where .= " AND ({$expression})::text = ?";
                $bindings[] = $dimensionKey;
            }
        }

        $source = $this->sourceFor($parameters);
        $total = (int) (DB::selectOne($this->withTimezone("SELECT count(DISTINCT {$key}) AS n FROM {$source} WHERE {$where}", $parameters), $bindings)->n ?? 0);
        $ids = DB::select(
            $this->withTimezone(sprintf('SELECT DISTINCT %1$s AS id FROM %2$s WHERE %3$s ORDER BY 1 LIMIT %4$d OFFSET %5$d', $key, $source, $where, $perPage, ($page - 1) * $perPage), $parameters),
            $bindings,
        );

        return ['ids' => array_map(fn (object $row): string => (string) $row->id, $ids), 'total' => $total];
    }

    /** @return array<string, int|float|null> */
    private function totals(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        [$where, $bindings] = $this->where($parameters, $from, $to);
        $row = DB::selectOne(
            $this->withTimezone(sprintf('SELECT %s, count(*) AS "_records" FROM %s WHERE %s', $this->measureSql($parameters), $this->sourceFor($parameters), $where), $parameters),
            $bindings,
        );

        return [...$this->values($row, $parameters->measures), '_records' => (int) ($row->_records ?? 0)];
    }

    /** @return array{string, list<mixed>} */
    private function where(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        // The source's own placeholders come first: the FROM clause precedes the WHERE clause.
        $bindings = [...$this->sourceBindings($parameters, $from, $to), $parameters->tenantId];
        $clauses = ["{$this->tenantColumn()} = ?"];
        if ($this->periodApplies()) {
            array_push($clauses, "{$this->periodColumn()} >= ?", "{$this->periodColumn()} < ?");
            array_push($bindings, $from, $to);
        }

        foreach ($parameters->filters as $name => $values) {
            $filter = $this->filters()[$name];
            if ($filter->type === 'boolean') {
                $clauses[] = "({$filter->sql}) = ?";
                $bindings[] = in_array($values[0] ?? 'false', ['true', '1'], true);

                continue;
            }
            $present = array_values(array_diff($values, ['none']));
            $parts = [];
            if ($present !== []) {
                $parts[] = "({$filter->sql})::text IN (".implode(', ', array_fill(0, count($present), '?')).')';
                array_push($bindings, ...$present);
            }
            if (in_array('none', $values, true)) {
                $parts[] = "({$filter->sql}) IS NULL";
            }
            $clauses[] = '('.implode(' OR ', $parts).')';
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    private function measureSql(ReportParameters $parameters): string
    {
        $all = $this->measures();

        return implode(', ', array_map(
            fn (string $key): string => "{$all[$key]->sql} AS \"m_{$key}\"",
            $parameters->measures,
        ));
    }

    /**
     * @param  list<string>  $measures
     * @return array<string, int|float|null>
     */
    private function values(?object $row, array $measures): array
    {
        $values = [];
        foreach ($measures as $key) {
            $value = $row === null ? null : ($row->{"m_{$key}"} ?? null);
            $values[$key] = $value === null ? null : (is_numeric($value) ? $value + 0 : null);
        }

        return $values;
    }

    private function withTimezone(string $sql, ReportParameters $parameters): string
    {
        return str_replace('{tz}', (string) DB::getPdo()->quote($parameters->timezone), $sql);
    }
}
