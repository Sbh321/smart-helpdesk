<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\History;

/** A record reconstructed at an instant, and how it differs from now. */
final readonly class AsOfView
{
    /**
     * @param  array<string, mixed>|null  $attributes  null when the record did not exist at that instant
     * @param  array<string, array{then: mixed, now: mixed}>  $differences
     */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $at,
        public ?array $attributes,
        public array $differences,
        public int $versionsAfter,
    ) {}
}
