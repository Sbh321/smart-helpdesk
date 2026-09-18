<?php

declare(strict_types=1);

namespace Tests\Contracts;

use App\Modules\Automation\Contracts\DuplicateStrategy;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Expectations every DuplicateStrategy must meet (ADR-0023 §2). Call from a *Test.php file.
 */
final class DuplicateStrategyContract
{
    /**
     * @param  Closure(): DuplicateStrategy  $make
     */
    public static function register(string $label, Closure $make): void
    {
        describe("{$label} (DuplicateStrategy contract)", function () use ($make): void {
            it('gives the same result for the same input', function () use ($make): void {
                expect($make()->find(DuplicateStrategyContract::ticket(), DuplicateStrategyContract::candidates()))->toEqual($make()->find(DuplicateStrategyContract::ticket(), DuplicateStrategyContract::candidates()));
            });

            it('does not depend on the order of the candidates', function () use ($make): void {
                $expected = $make()->find(DuplicateStrategyContract::ticket(), DuplicateStrategyContract::candidates());
                mt_srand(7);

                for ($i = 0; $i < 20; $i++) {
                    $candidates = DuplicateStrategyContract::candidates();
                    shuffle($candidates);

                    expect($make()->find(DuplicateStrategyContract::ticket(), $candidates))->toEqual($expected);
                }
            });

            it('suggests nothing without candidates, and handles empty text', function () use ($make): void {
                expect($make()->find(DuplicateStrategyContract::ticket(), [])->matches)->toBe([])
                    ->and($make()->find(new TicketText('t0', '', ''), [new TicketText('t1', '', '')])->matches)->toBe([]);
            });

            it('finds an identical ticket first, never the ticket itself, best first and within 0–1', function () use ($make): void {
                $result = $make()->find(DuplicateStrategyContract::ticket(), DuplicateStrategyContract::candidates());
                $scores = array_map(static fn ($m): float => $m->score(), $result->matches);
                $sorted = $scores;
                rsort($sorted);

                expect($result->matches)->not->toBeEmpty()
                    ->and($result->matches[0]->ticketId)->toBe('copy')
                    ->and($result->matches[0]->score())->toBe(1.0)
                    ->and(array_map(static fn ($m): string => $m->ticketId, $result->matches))->not->toContain('new')
                    ->and($scores)->toBe($sorted)
                    ->and(min($scores))->toBeGreaterThanOrEqual(0.0)
                    ->and(max($scores))->toBeLessThanOrEqual(1.0);
            });

            it('explains itself with strategy, version and shared words', function () use ($make): void {
                $result = $make()->find(DuplicateStrategyContract::ticket(), DuplicateStrategyContract::candidates());
                $explanation = $result->explanation();

                expect($explanation['strategy'])->toBe($result->strategy)->not->toBeEmpty()
                    ->and($explanation['strategy_version'])->toBe($result->strategyVersion)->not->toBeEmpty()
                    ->and($explanation['matches'][0])->toHaveKeys(['ticket_id', 'score', 'shared_words'])
                    ->and(json_encode($explanation, JSON_THROW_ON_ERROR))->toBeString();
            });
        });
    }

    public static function ticket(): TicketText
    {
        return new TicketText('new', 'Cannot login after password reset', 'Login page shows error ERR-401 after I reset my password.');
    }

    /**
     * @return list<TicketText>
     */
    public static function candidates(): array
    {
        $day = static fn (int $d): CarbonImmutable => CarbonImmutable::parse("2026-09-{$d} 10:00", 'UTC');

        return [
            new TicketText('new', 'Cannot login after password reset', 'Login page shows error ERR-401 after I reset my password.', $day(17)),
            new TicketText('copy', 'Cannot login after password reset', 'Login page shows error ERR-401 after I reset my password.', $day(10)),
            new TicketText('c1031', 'Login fails after resetting password', 'Error ERR-401 on login page.', $day(12)),
            new TicketText('c1032', 'Login fails after resetting password', 'Error ERR-401 on login page.', $day(14)),
            new TicketText('c1002', 'Invoice PDF is blank', 'The invoice download shows an empty page.', $day(11)),
            new TicketText('c1003', 'Password reset email never arrives', 'No reset email after requesting a password reset.', $day(9)),
        ];
    }
}
