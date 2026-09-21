<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Exceptions\NoEligibleAgentFound;
use App\Modules\Automation\Domain\Exceptions\PriorityOverrideNotAllowed;
use App\Modules\Automation\Domain\Exceptions\TicketAlreadyAssigned;
use App\Modules\Automation\Domain\Exceptions\TicketNotAssigned;
use App\Support\Errors\ErrorCode;

// The problem codes and meta the Automation domain refusals carry (docs/07-api/errors.md).

it('maps every refusal to its stable code, status and meta', function (Closure $make, ErrorCode $code, int $status, array $meta): void {
    $exception = $make(); // built inside the test, so coverage sees the factory
    expect($exception->code())->toBe($code)
        ->and($exception->status())->toBe($status)
        ->and($exception->meta())->toMatchArray($meta)
        ->and($exception->getMessage())->not->toBe('');
})->with([
    'no eligible agent' => [
        fn () => NoEligibleAgentFound::for('t-1', 'team-1', [['agent_id' => 'a-1', 'reasons' => ['not_available']]]),
        ErrorCode::NoEligibleAgent, 422,
        ['ticket_id' => 't-1', 'team_id' => 'team-1', 'exclusions' => [['agent_id' => 'a-1', 'reasons' => ['not_available']]]],
    ],
    'override on a closed ticket' => [fn () => PriorityOverrideNotAllowed::forStatus('t-2', 'closed'), ErrorCode::Conflict, 409, ['ticket_id' => 't-2']],
    'already assigned' => [fn () => TicketAlreadyAssigned::to('t-3', 'a-3', null), ErrorCode::AlreadyAssigned, 409, ['ticket_id' => 't-3', 'agent_id' => 'a-3']],
    'nothing to unassign' => [fn () => TicketNotAssigned::for('t-4', 'open'), ErrorCode::Conflict, 409, ['ticket_id' => 't-4', 'status' => 'open']],
]);
