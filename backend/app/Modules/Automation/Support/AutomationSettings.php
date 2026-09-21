<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;
use App\Modules\Automation\Domain\Priority\PrioritySettings;
use App\Modules\Tenancy\Settings\ArraySection;
use App\Modules\Tenancy\Settings\SettingsRegistry;

/**
 * The workspace settings Automation owns: `automation.priority`, `automation.assignment`,
 * `automation.duplicates`. Keys are namespaced per strategy (`baseline`), so a replacement strategy
 * brings its own keys (ADR-0023). The settings value objects have the last word on validity.
 */
final class AutomationSettings
{
    public static function register(SettingsRegistry $registry): void
    {
        $registry->register(ArraySection::fromConfig('automation.priority', [
            'baseline' => ['required', 'array'],
            'baseline.weights' => ['required', 'array:impact,urgency,tier,age'],
            'baseline.weights.*' => ['required', 'numeric', 'between:0,1'],
            'baseline.thresholds' => ['required', 'array:P1,P2,P3'],
            'baseline.thresholds.*' => ['required', 'numeric', 'between:0,100'],
            'baseline.age_full_hours' => ['required', 'numeric', 'min:1', 'max:8760'],
        ], function (array $values): array {
            $baseline = (array) ($values['baseline'] ?? []);
            $errors = [];
            if (abs(array_sum((array) ($baseline['weights'] ?? [])) - 1.0) > 0.001) {
                $errors['baseline.weights'] = 'The four weights must add up to 1.';
            }
            $thresholds = (array) ($baseline['thresholds'] ?? []);
            if (! (($thresholds['P1'] ?? 0) > ($thresholds['P2'] ?? 0) && ($thresholds['P2'] ?? 0) > ($thresholds['P3'] ?? 0))) {
                $errors['baseline.thresholds'] = 'Thresholds must decrease from P1 to P3.';
            }

            return $errors !== [] ? $errors : self::accepted(fn () => PrioritySettings::fromArray($baseline));
        }));

        $registry->register(ArraySection::fromConfig('automation.assignment', [
            'enabled' => ['required', 'boolean'],
        ]));

        $registry->register(ArraySection::fromConfig('automation.duplicates', [
            'baseline' => ['required', 'array'],
            'baseline.threshold' => ['required', 'numeric', 'between:0,1'],
            'baseline.candidate_limit' => ['required', 'integer', 'between:1,200'],
            'baseline.window_days' => ['required', 'integer', 'between:1,365'],
            'baseline.max_suggestions' => ['required', 'integer', 'between:1,20'],
        ], fn (array $values): array => self::accepted(fn () => DuplicateSettings::fromArray((array) ($values['baseline'] ?? [])))));
    }

    /**
     * @param  callable(): mixed  $build
     * @return array<string, string>
     */
    private static function accepted(callable $build): array
    {
        try {
            $build();

            return [];
        } catch (InvalidStrategySettings $exception) {
            return ['baseline' => $exception->getMessage()];
        }
    }
}
