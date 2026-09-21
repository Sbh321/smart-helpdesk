<?php

declare(strict_types=1);

namespace App\Support\ApiDocs;

use Attribute;

/**
 * Documents a JSON request body whose keys are not known statically (for example a settings section,
 * validated against the section's own rules). Without it Scramble documents no body at all.
 * Read by {@see OperationConventions}.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class FreeFormRequestBody
{
    /**
     * @param  array<string, mixed>|null  $example
     */
    public function __construct(
        public string $description,
        public ?array $example = null,
    ) {}
}
