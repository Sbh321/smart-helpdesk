<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Overviews;

/** The 360 view of one record (a typed view, so the API schema can be inferred). */
final readonly class EntityOverview
{
    /**
     * @param  array<string, int|float|string|null>  $metrics  numbers and short values (tier, availability, SLA outcomes)
     * @param  array<string, mixed>  $related  related records by kind
     * @param  array<string, list<array<string, mixed>>>  $trends  series by name
     */
    public function __construct(
        public string $entity,
        public string $id,
        public string $title,
        public array $metrics,
        public array $related,
        public array $trends,
    ) {}

    /** @param array{entity: string, id: string, title: string, metrics: array<string, int|float|string|null>, related: array<string, mixed>, trends: array<string, list<array<string, mixed>>>} $data */
    public static function from(array $data): self
    {
        return new self($data['entity'], $data['id'], $data['title'], $data['metrics'], $data['related'], $data['trends']);
    }
}
