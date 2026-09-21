<?php

declare(strict_types=1);

use App\Modules\Automation\Console\Experiments\Policies\RandomPolicy;
use App\Modules\Automation\Console\Experiments\Policies\RoundRobinPolicy;
use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use App\Support\Experiments\SeededRandom;

/** @return list<AgentCandidate> */
function comparisonAgents(): array
{
    return [
        new AgentCandidate('c', 9, 5, ['billing']),                      // over capacity: still eligible here
        new AgentCandidate('a', 0, 5, ['billing', 'network']),
        new AgentCandidate('b', 0, 5, ['network']),
        new AgentCandidate('d', 0, 5, ['billing'], available: false),
        new AgentCandidate('e', 0, 5, ['billing'], active: false),
    ];
}

it('lets skilled agents take turns in id order, ignoring capacity', function (): void {
    $policy = new RoundRobinPolicy;
    $billing = new TicketNeeds('t', ['billing']);

    $chosen = array_map(fn (): ?string => $policy->choose($billing, comparisonAgents())->agentId, range(1, 5));

    expect($chosen)->toBe(['a', 'c', 'a', 'c', 'a']);
});

it('continues the rotation after the last agent across categories', function (): void {
    $policy = new RoundRobinPolicy;

    expect($policy->choose(new TicketNeeds('1'), comparisonAgents())->agentId)->toBe('a')
        ->and($policy->choose(new TicketNeeds('2', ['network']), comparisonAgents())->agentId)->toBe('b')
        ->and($policy->choose(new TicketNeeds('3', ['billing']), comparisonAgents())->agentId)->toBe('c')
        ->and($policy->choose(new TicketNeeds('4', ['network']), comparisonAgents())->agentId)->toBe('a');
});

it('does not depend on the order of the candidates', function (): void {
    $first = new RoundRobinPolicy;
    $second = new RoundRobinPolicy;
    $needs = new TicketNeeds('t', ['billing']);

    expect($first->choose($needs, comparisonAgents())->agentId)
        ->toBe($second->choose($needs, array_reverse(comparisonAgents()))->agentId);
});

it('picks a random skilled agent, repeatably for a seed', function (): void {
    $pick = function (int $seed): array {
        $policy = new RandomPolicy(new SeededRandom($seed));

        return array_map(fn (): ?string => $policy->choose(new TicketNeeds('t', ['billing']), comparisonAgents())->agentId, range(1, 40));
    };

    expect(array_unique($pick(42)))->toEqualCanonicalizing(['a', 'c'])
        ->and($pick(42))->toBe($pick(42))
        ->and($pick(42))->not->toBe($pick(1));
});

it('assigns nobody when no agent has the skills', function (): void {
    $needs = new TicketNeeds('t', ['hardware']);

    expect((new RandomPolicy(new SeededRandom(1)))->choose($needs, comparisonAgents())->assigned())->toBeFalse()
        ->and((new RoundRobinPolicy)->choose($needs, comparisonAgents())->agentId)->toBeNull();
});

it('names the comparison policies in their results', function (): void {
    $result = (new RoundRobinPolicy)->choose(new TicketNeeds('t'), comparisonAgents());

    expect($result->strategy)->toBe('round_robin')
        ->and((new RandomPolicy(new SeededRandom(1)))->choose(new TicketNeeds('t'), comparisonAgents())->strategy)->toBe('random');
});
