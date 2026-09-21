<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use Carbon\CarbonImmutable;

/**
 * A validated request: the period (half-open, UTC), the previous period of the same length for the
 * comparison, one dimension, the measures, filters and the workspace time zone.
 */
final readonly class ReportParameters
{
    /**
     * @param  list<string>  $measures
     * @param  array<string, list<string>>  $filters
     */
    public function __construct(
        public string $tenantId,
        public string $timezone,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $dimension,
        public array $measures,
        public array $filters,
        public bool $compare,
        public ?string $period = null,
    ) {}

    public function previousFrom(): CarbonImmutable
    {
        return $this->from->subSeconds($this->to->getTimestamp() - $this->from->getTimestamp());
    }

    /** @return array<string, mixed> for the cache key and the response */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from->toIso8601ZuluString(),
            'to' => $this->to->toIso8601ZuluString(),
            'group' => $this->dimension,
            'measures' => $this->measures,
            'filters' => $this->filters,
            'compare' => $this->compare,
            'timezone' => $this->timezone,
        ];
    }
}
