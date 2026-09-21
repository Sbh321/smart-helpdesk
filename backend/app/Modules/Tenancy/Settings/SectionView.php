<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings;

/**
 * One section as the API shows it: effective values, the code defaults and the settings version.
 */
final readonly class SectionView
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(
        public string $section,
        public int $version,
        public array $values,
        public array $defaults,
    ) {}
}
