<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Turns request input into validated `ReportParameters` and runs the report, cached for five minutes
 * per workspace and parameter set (ADR-0022 §4). Periods are whole days in the workspace time zone.
 */
final readonly class ReportRunner
{
    public const int CACHE_SECONDS = 300;

    public const array PERIODS = ['today', 'yesterday', 'last_7d', 'last_30d', 'last_90d', 'this_month', 'last_month', 'this_year'];

    public function __construct(private Clock $clock) {}

    public function run(ReportDefinition $report, ReportParameters $parameters): ReportResult
    {
        $key = 'report:'.$report->key().':'.md5((string) json_encode($parameters->toArray()));

        // Plain arrays in the cache: the cache store refuses to unserialize application classes
        // (config/cache.php `serializable_classes`), so a cached object would come back incomplete.
        /** @var array{rows: list<array{key: string, label: string, values: array<string, int|float|null>}>, totals: array<string, int|float|null>, previous: array<string, int|float|null>|null, truncated: bool} $cached */
        $cached = Cache::remember($key, self::CACHE_SECONDS, function () use ($report, $parameters): array {
            $result = $report->run($parameters);

            return ['rows' => $result->rows, 'totals' => $result->totals, 'previous' => $result->previous, 'truncated' => $result->truncated];
        });

        return new ReportResult($cached['rows'], $cached['totals'], $cached['previous'], $cached['truncated']);
    }

    /**
     * @param  array<string, mixed>  $input  `period` or `from`+`to` (YYYY-MM-DD, inclusive), `group`, `measures`, `filter`, `compare`
     *
     * @throws ValidationException
     */
    public function parameters(ReportDefinition $report, array $input, string $tenantId, string $timezone): ReportParameters
    {
        $errors = [];
        [$from, $to, $period] = $this->period($input, $timezone, $errors);

        $group = is_string($input['group'] ?? null) && $input['group'] !== '' ? $input['group'] : $report->defaultDimension();
        if (! array_key_exists($group, $report->dimensions())) {
            $errors['group'][] = 'Group by one of: '.implode(', ', array_keys($report->dimensions())).'.';
        }

        $measures = $this->list($input['measures'] ?? null) ?: array_keys($report->measures());
        $unknownMeasures = array_diff($measures, array_keys($report->measures()));
        if ($unknownMeasures !== []) {
            $errors['measures'][] = 'Unknown measure: '.implode(', ', $unknownMeasures).'.';
        }

        $filters = [];
        foreach ((array) ($input['filter'] ?? []) as $name => $value) {
            if (! array_key_exists((string) $name, $report->filters())) {
                $errors["filter.{$name}"][] = 'This report has no such filter.';

                continue;
            }
            $values = $this->list($value);
            if ($values === [] || count($values) > 50) {
                $errors["filter.{$name}"][] = 'Give 1 to 50 values.';

                continue;
            }
            $filters[(string) $name] = $values;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new ReportParameters(
            $tenantId, $timezone, $from, $to, $group, $measures, $filters,
            filter_var($input['compare'] ?? false, FILTER_VALIDATE_BOOLEAN), $period,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return array{CarbonImmutable, CarbonImmutable, string|null}
     */
    private function period(array $input, string $timezone, array &$errors): array
    {
        $today = $this->clock->now()->setTimezone($timezone)->startOfDay();
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;

        if (is_string($from) || is_string($to)) {
            $valid = fn (mixed $date): bool => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
            if (! $valid($from) || ! $valid($to) || $from > $to) {
                $errors['from'][] = 'Give from and to as YYYY-MM-DD, from first.';

                return [$today, $today->addDay(), null];
            }
            $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
            $end = CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay();
            if ($start->diffInDays($end) > 731) {
                $errors['from'][] = 'A period can be at most two years.';
            }

            return [$start->utc(), $end->utc(), null];
        }

        $period = is_string($input['period'] ?? null) ? $input['period'] : 'last_30d';
        if (! in_array($period, self::PERIODS, true)) {
            $errors['period'][] = 'Use one of: '.implode(', ', self::PERIODS).'.';
            $period = 'last_30d';
        }

        [$start, $end] = match ($period) {
            'today' => [$today, $today->addDay()],
            'yesterday' => [$today->subDay(), $today],
            'last_7d' => [$today->subDays(6), $today->addDay()],
            'last_30d' => [$today->subDays(29), $today->addDay()],
            'last_90d' => [$today->subDays(89), $today->addDay()],
            'this_month' => [$today->startOfMonth(), $today->addDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()],
            'this_year' => [$today->startOfYear(), $today->addDay()],
        };

        return [$start->utc(), $end->utc(), $period];
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), fn (string $item): bool => $item !== ''));
        }

        return is_string($value) ? array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== '')) : [];
    }
}
