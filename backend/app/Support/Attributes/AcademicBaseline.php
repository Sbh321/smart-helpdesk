<?php

declare(strict_types=1);

namespace App\Support\Attributes;

use Attribute;

/**
 * Marks a minimal algorithm implementation built for the CACS452 project defence.
 * It must be replaced by a stronger strategy afterwards (ADR-0023).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AcademicBaseline
{
    public function __construct(
        public string $replaceAfter = 'CACS452 project defence',
        public string $decisionRecord = 'docs/adr/0023-minimal-replaceable-algorithms.md',
    ) {}
}
