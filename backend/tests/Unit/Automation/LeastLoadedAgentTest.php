<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\ExclusionReason;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;
use App\Modules\Automation\Strategies\Baseline\LeastLoadedAgent;
use App\Support\Attributes\AcademicBaseline;
use Carbon\CarbonImmutable;

function assignedAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-09-17 {$time}", 'Asia/Kathmandu');
}

// docs/05-algorithms/agent-assignment.md §Worked example.
it('reproduces the worked example: Chen wins the tie with Asha', function (): void {
    $agents = [
        new AgentCandidate('asha', 3, 10, ['billing'], lastAssignedAt: assignedAt('09:40')),
        new AgentCandidate('bikram', 2, 5, ['billing'], lastAssignedAt: assignedAt('08:15')),
        new AgentCandidate('chen', 3, 10, ['billing'], lastAssignedAt: assignedAt('09:10')),
        new AgentCandidate('dev', 0, 8, []),
        new AgentCandidate('esha', 1, 10, ['billing'], available: false),
    ];

    $result = (new LeastLoadedAgent)->choose(new TicketNeeds('t-1', ['billing']), $agents);
    $explanation = $result->explanation('t-1');

    expect($result->agentId)->toBe('chen')
        ->and($result->assigned())->toBeTrue()
        ->and(array_column($explanation['ranking'], 'agent_id'))->toBe(['chen', 'asha', 'bikram'])
        ->and(array_column($explanation['ranking'], 'load'))->toBe([0.3, 0.3, 0.4])
        ->and($explanation['ranking'][0])->toBe([
            'rank' => 1, 'agent_id' => 'chen', 'open_tickets' => 3, 'capacity' => 10, 'load' => 0.3,
            'last_assigned_at' => '2026-09-17T09:10:00+05:45',
        ])
        ->and($explanation['excluded'])->toBe([
            ['agent_id' => 'dev', 'reason' => 'missing_skill', 'missing_skills' => ['billing']],
            ['agent_id' => 'esha', 'reason' => 'not_available'],
        ])
        ->and($explanation)->toMatchArray([
            'strategy' => 'least_loaded_agent', 'strategy_version' => '1.0.0',
            'ticket_id' => 't-1', 'agent_id' => 'chen', 'outcome' => 'assigned',
        ]);
});

it('records each exclusion reason', function (AgentCandidate $agent, TicketNeeds $needs, ExclusionReason $reason): void {
    $result = (new LeastLoadedAgent)->choose($needs, [$agent]);

    expect($result->agentId)->toBeNull()
        ->and($result->exclusions)->toHaveCount(1)
        ->and($result->exclusions[0]->reason)->toBe($reason);
})->with([
    'inactive' => [new AgentCandidate('a', 0, 5, active: false, available: false), new TicketNeeds('t'), ExclusionReason::Inactive],
    'not available' => [new AgentCandidate('a', 0, 5, available: false), new TicketNeeds('t'), ExclusionReason::NotAvailable],
    'off shift when shifts are enforced' => [new AgentCandidate('a', 0, 5, onShift: false), new TicketNeeds('t', enforceShifts: true), ExclusionReason::OffShift],
    'missing skill' => [new AgentCandidate('a', 0, 5, ['network']), new TicketNeeds('t', ['billing', 'network']), ExclusionReason::MissingSkill],
    'not in team' => [new AgentCandidate('a', 0, 5, teamIds: ['team-b']), new TicketNeeds('t', teamId: 'team-a'), ExclusionReason::NotInTeam],
    'at capacity' => [new AgentCandidate('a', 5, 5), new TicketNeeds('t'), ExclusionReason::AtCapacity],
    'zero capacity' => [new AgentCandidate('a', 0, 0), new TicketNeeds('t'), ExclusionReason::AtCapacity],
]);

it('ignores shifts unless the tenant enforces them', function (): void {
    $result = (new LeastLoadedAgent)->choose(new TicketNeeds('t'), [new AgentCandidate('a', 0, 5, onShift: false)]);

    expect($result->agentId)->toBe('a');
});

it('accepts any agent when the category needs no skill and lists missing skills once, sorted', function (): void {
    $strategy = new LeastLoadedAgent;

    expect($strategy->choose(new TicketNeeds('t'), [new AgentCandidate('a', 0, 5)])->agentId)->toBe('a')
        ->and($strategy->choose(new TicketNeeds('t', ['vpn', 'billing', 'vpn']), [new AgentCandidate('a', 0, 5)])->exclusions[0]->missingSkills)
        ->toBe(['billing', 'vpn']);
});

it('picks the lowest load, not the fewest tickets', function (): void {
    $result = (new LeastLoadedAgent)->choose(new TicketNeeds('t'), [
        new AgentCandidate('few', 2, 4),
        new AgentCandidate('many', 4, 10),
    ]);

    expect($result->agentId)->toBe('many');
});

it('gives a tie to the never-assigned agent, then to the smallest id', function (): void {
    $strategy = new LeastLoadedAgent;

    expect($strategy->choose(new TicketNeeds('t'), [
        new AgentCandidate('b', 1, 5, lastAssignedAt: assignedAt('08:00')),
        new AgentCandidate('c', 1, 5),
    ])->agentId)->toBe('c')
        ->and($strategy->choose(new TicketNeeds('t'), [
            new AgentCandidate('b', 1, 5, lastAssignedAt: assignedAt('08:00')),
            new AgentCandidate('z', 1, 5),
            new AgentCandidate('y', 1, 5),
        ])->agentId)->toBe('y')
        ->and($strategy->choose(new TicketNeeds('t'), [
            new AgentCandidate('b', 2, 10, lastAssignedAt: assignedAt('08:00')),
            new AgentCandidate('a', 1, 5, lastAssignedAt: assignedAt('08:00')),
        ])->agentId)->toBe('a');
});

it('rotates identical agents like round robin', function (): void {
    $strategy = new LeastLoadedAgent;
    $clock = CarbonImmutable::parse('2026-09-17 09:00', 'UTC');
    $agents = ['a' => null, 'b' => null, 'c' => null];
    $order = [];

    // Tickets are resolved as fast as they arrive, so only the last-assignment time separates the agents.
    for ($i = 0; $i < 6; $i++) {
        $candidates = [];

        foreach ($agents as $id => $last) {
            $candidates[] = new AgentCandidate((string) $id, 1, 5, lastAssignedAt: $last);
        }

        $chosen = (string) $strategy->choose(new TicketNeeds("t{$i}"), $candidates)->agentId;
        $order[] = $chosen;
        $agents[$chosen] = $clock = $clock->addMinute();
    }

    expect($order)->toBe(['a', 'b', 'c', 'a', 'b', 'c']);
});

it('spreads work by load when tickets stay open', function (): void {
    $strategy = new LeastLoadedAgent;
    $open = ['a' => 0, 'b' => 0];
    $capacity = ['a' => 2, 'b' => 4];

    for ($i = 0; $i < 6; $i++) {
        $candidates = array_map(static fn (string $id): AgentCandidate => new AgentCandidate($id, $open[$id], $capacity[$id]), array_keys($open));
        $open[(string) $strategy->choose(new TicketNeeds("t{$i}"), $candidates)->agentId]++;
    }

    expect($open)->toBe(['a' => 2, 'b' => 4])
        ->and($strategy->choose(new TicketNeeds('t7'), [new AgentCandidate('a', 2, 2), new AgentCandidate('b', 4, 4)])->explanation('t7')['outcome'])
        ->toBe('no_eligible_agent');
});

it('reports the load of an agent and rejects negative counts', function (): void {
    expect((new AgentCandidate('a', 3, 10))->load())->toBe(0.3)
        ->and((new AgentCandidate('a', 0, 0))->load())->toBe(1.0)
        ->and(fn () => new AgentCandidate('a', -1, 5))->toThrow(InvalidStrategySettings::class)
        ->and(fn () => new AgentCandidate('a', 0, -5))->toThrow(InvalidStrategySettings::class);
});

it('is marked as an academic baseline', function (): void {
    expect((new ReflectionClass(LeastLoadedAgent::class))->getAttributes(AcademicBaseline::class))->toHaveCount(1)
        ->and((string) (new ReflectionClass(LeastLoadedAgent::class))->getDocComment())->toContain('@deprecated');
});
