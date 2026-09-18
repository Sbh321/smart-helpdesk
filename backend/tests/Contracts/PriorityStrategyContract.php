<?php

declare(strict_types=1);

namespace Tests\Contracts;

use App\Modules\Automation\Contracts\PriorityStrategy;
use App\Modules\Automation\Domain\Priority\CustomerTier;
use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PriorityLevel;
use Closure;

/**
 * Expectations every PriorityStrategy must meet (ADR-0023 §2). Call from a *Test.php file.
 */
final class PriorityStrategyContract
{
    /**
     * @param  Closure(): PriorityStrategy  $make
     */
    public static function register(string $label, Closure $make): void
    {
        describe("{$label} (PriorityStrategy contract)", function () use ($make): void {
            it('gives the same result for the same input', function () use ($make): void {
                $input = new PriorityInput(3, 2, CustomerTier::Premium, 30.0);

                expect($make()->score($input))->toEqual($make()->score($input))
                    ->and($make()->score($input))->toEqual($make()->score($input));
            });

            it('is not influenced by earlier calls', function () use ($make): void {
                $strategy = $make();
                $input = new PriorityInput(2, 3, CustomerTier::Standard, 5.0);
                $first = $strategy->score($input);
                $strategy->score(new PriorityInput(4, 4, CustomerTier::Enterprise, 500.0));

                expect($strategy->score($input))->toEqual($first);
            });

            it('keeps every score within 0–100 and orders levels by score', function () use ($make): void {
                $strategy = $make();
                $rank = [PriorityLevel::P4->value => 0, PriorityLevel::P3->value => 1, PriorityLevel::P2->value => 2, PriorityLevel::P1->value => 3];
                $results = [];

                foreach (PriorityStrategyContract::grid() as $input) {
                    $result = $strategy->score($input);
                    expect($result->score)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(100.0);
                    $results[] = $result;
                }

                usort($results, static fn ($a, $b): int => $a->score <=> $b->score);

                for ($i = 1; $i < count($results); $i++) {
                    expect($rank[$results[$i]->level->value])->toBeGreaterThanOrEqual($rank[$results[$i - 1]->level->value]);
                }
            });

            it('explains itself with strategy, version and parts adding up to the score', function () use ($make): void {
                foreach (PriorityStrategyContract::grid() as $input) {
                    $result = $make()->score($input);
                    $explanation = $result->explanation();

                    expect($explanation['strategy'])->toBeString()->not->toBeEmpty()
                        ->and($explanation['strategy_version'])->toBeString()->not->toBeEmpty()
                        ->and($explanation['strategy'])->toBe($result->strategy)
                        ->and($explanation['level'])->toBe($result->level->value)
                        ->and($explanation['parts'])->not->toBeEmpty()
                        ->and(array_sum(array_column($explanation['parts'], 'contribution')))->toEqualWithDelta($result->score, 0.05)
                        ->and(json_encode($explanation, JSON_THROW_ON_ERROR))->toBeString();
                }
            });

            it('lets a manual level win without changing the score', function () use ($make): void {
                $result = $make()->score(new PriorityInput(1, 1, CustomerTier::Standard));

                expect($result->effectiveLevel(PriorityLevel::P1))->toBe(PriorityLevel::P1)
                    ->and($result->effectiveLevel(null))->toBe($result->level)
                    ->and($result->explanation(PriorityLevel::P1)['effective_level'])->toBe('P1')
                    ->and($result->explanation(PriorityLevel::P1)['score'])->toBe($result->score);
            });
        });
    }

    /**
     * @return list<PriorityInput>
     */
    public static function grid(): array
    {
        $inputs = [];

        foreach ([1, 2, 3, 4] as $impact) {
            foreach ([1, 2, 3, 4] as $urgency) {
                foreach (CustomerTier::cases() as $tier) {
                    foreach ([0.0, 24.0, 72.0, 1000.0] as $hours) {
                        $inputs[] = new PriorityInput($impact, $urgency, $tier, $hours);
                    }
                }
            }
        }

        return $inputs;
    }
}
