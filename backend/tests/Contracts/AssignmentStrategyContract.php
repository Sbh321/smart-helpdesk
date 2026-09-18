<?php

declare(strict_types=1);

namespace Tests\Contracts;

use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Expectations every AssignmentStrategy must meet (ADR-0023 §2). Call from a *Test.php file.
 */
final class AssignmentStrategyContract
{
    /**
     * @param  Closure(): AssignmentStrategy  $make
     */
    public static function register(string $label, Closure $make): void
    {
        describe("{$label} (AssignmentStrategy contract)", function () use ($make): void {
            it('gives the same result for the same input', function () use ($make): void {
                $needs = new TicketNeeds('ticket-1', ['billing']);

                expect($make()->choose($needs, AssignmentStrategyContract::agents()))->toEqual($make()->choose($needs, AssignmentStrategyContract::agents()));
            });

            it('does not depend on the order of the candidates', function () use ($make): void {
                $needs = new TicketNeeds('ticket-1', ['billing']);
                $expected = $make()->choose($needs, AssignmentStrategyContract::agents());
                mt_srand(42);

                for ($i = 0; $i < 20; $i++) {
                    $agents = AssignmentStrategyContract::agents();
                    shuffle($agents);

                    expect($make()->choose($needs, $agents))->toEqual($expected);
                }
            });

            it('assigns nobody when there are no candidates', function () use ($make): void {
                $result = $make()->choose(new TicketNeeds('ticket-1'), []);

                expect($result->assigned())->toBeFalse()
                    ->and($result->agentId)->toBeNull()
                    ->and($result->explanation('ticket-1')['outcome'])->toBe('no_eligible_agent');
            });

            it('only chooses an agent who is active, available, skilled, in the team and below capacity', function () use ($make): void {
                $result = $make()->choose(new TicketNeeds('ticket-1', ['billing'], 'team-a'), AssignmentStrategyContract::agents());
                $chosen = array_values(array_filter(AssignmentStrategyContract::agents(), static fn (AgentCandidate $a): bool => $a->id === $result->agentId))[0];

                expect($chosen->active && $chosen->available)->toBeTrue()
                    ->and($chosen->skills)->toContain('billing')
                    ->and($chosen->teamIds)->toContain('team-a')
                    ->and($chosen->openTickets)->toBeLessThan($chosen->capacity);
            });

            it('explains every candidate exactly once, with strategy and version', function () use ($make): void {
                $result = $make()->choose(new TicketNeeds('ticket-1', ['billing']), AssignmentStrategyContract::agents());
                $explanation = $result->explanation('ticket-1');
                $seen = [...array_column($explanation['ranking'], 'agent_id'), ...array_column($explanation['excluded'], 'agent_id')];
                sort($seen);
                $ids = array_map(static fn (AgentCandidate $a): string => $a->id, AssignmentStrategyContract::agents());
                sort($ids);

                expect($seen)->toBe($ids)
                    ->and($explanation['strategy'])->toBe($result->strategy)->not->toBeEmpty()
                    ->and($explanation['strategy_version'])->toBe($result->strategyVersion)->not->toBeEmpty()
                    ->and($explanation['agent_id'])->toBe($result->agentId)
                    ->and(json_encode($explanation, JSON_THROW_ON_ERROR))->toBeString();
            });
        });
    }

    /**
     * @return list<AgentCandidate>
     */
    public static function agents(): array
    {
        $at = static fn (string $time): CarbonImmutable => CarbonImmutable::parse("2026-09-17 {$time}", 'UTC');

        return [
            new AgentCandidate('a1', 3, 10, ['billing'], ['team-a'], lastAssignedAt: $at('09:40')),
            new AgentCandidate('a2', 2, 5, ['billing'], ['team-a'], lastAssignedAt: $at('08:15')),
            new AgentCandidate('a3', 3, 10, ['billing'], ['team-a'], lastAssignedAt: $at('09:10')),
            new AgentCandidate('a4', 0, 8, ['network'], ['team-a']),
            new AgentCandidate('a5', 1, 10, ['billing'], ['team-a'], available: false),
            new AgentCandidate('a6', 0, 10, ['billing'], ['team-b']),
            new AgentCandidate('a7', 4, 4, ['billing'], ['team-a']),
            new AgentCandidate('a8', 0, 10, ['billing'], ['team-a'], active: false),
            new AgentCandidate('a9', 3, 10, ['billing'], ['team-a'], lastAssignedAt: $at('09:10')),
        ];
    }
}
