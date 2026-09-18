<?php

declare(strict_types=1);

namespace App\Modules\Automation\Strategies\Baseline;

use App\Modules\Automation\Contracts\PriorityStrategy;
use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PriorityPart;
use App\Modules\Automation\Domain\Priority\PriorityResult;
use App\Modules\Automation\Domain\Priority\PrioritySettings;
use App\Support\Attributes\AcademicBaseline;

/**
 * Basic Weighted Priority: score = 100 × Σ weight × scaled value over impact, urgency, tier and age
 * (docs/05-algorithms/priority-scoring.md).
 *
 * @deprecated Academic baseline for the CACS452 defence; replace after the defence (docs/adr/0023-minimal-replaceable-algorithms.md).
 */
#[AcademicBaseline]
final readonly class BasicWeightedPriority implements PriorityStrategy
{
    public const string NAME = 'basic_weighted_priority';

    public const string VERSION = '1.0.0';

    public function __construct(private PrioritySettings $settings = new PrioritySettings) {}

    public function score(PriorityInput $input): PriorityResult
    {
        $values = [
            'impact' => ($input->impact - 1) / 3,
            'urgency' => ($input->urgency - 1) / 3,
            'tier' => $input->tier->scaled(),
            'age' => min(1.0, $input->hoursWaited / $this->settings->ageFullHours),
        ];

        $parts = [];
        $total = 0.0;

        foreach ($values as $name => $value) {
            $weight = $this->settings->weights[$name];
            $contribution = 100 * $weight * $value;
            $total += $contribution;
            $parts[] = new PriorityPart($name, $value, $weight, $contribution);
        }

        // Rounding to 6 places first removes float noise (e.g. 40.000000000000006) before the one-decimal display value.
        $score = round(round($total, 6), 1);

        return new PriorityResult(
            score: $score,
            level: $this->settings->levelFor($score),
            parts: $parts,
            strategy: self::NAME,
            strategyVersion: self::VERSION,
            settings: $this->settings->toArray(),
        );
    }
}
