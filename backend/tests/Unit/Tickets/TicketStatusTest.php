<?php

declare(strict_types=1);

use App\Modules\Tickets\Domain\Exceptions\InvalidTransition;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus as S;

/*
 * The whole transition table, cell by cell (docs/04-domain/tickets.md §State machine).
 * Every pair not listed as allowed must be refused.
 */
const ALLOWED_TRANSITIONS = [
    'open' => ['assigned', 'in_progress', 'closed'],
    'assigned' => ['in_progress', 'pending', 'resolved', 'open'],
    'in_progress' => ['pending', 'resolved', 'open'],
    'pending' => ['in_progress', 'resolved'],
    'resolved' => ['closed', 'in_progress'],
    'closed' => ['in_progress'],
];

dataset('every status pair', function (): array {
    $pairs = [];
    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            $pairs["{$from->value} → {$to->value}"] = [$from, $to];
        }
    }

    return $pairs;
});

it('allows exactly the documented transitions', function (S $from, S $to): void {
    $expected = in_array($to->value, ALLOWED_TRANSITIONS[$from->value], true);

    expect($from->canTransitionTo($to))->toBe($expected);

    if ($expected) {
        expect($from->transitionTo($to))->toBe($to);
    } else {
        expect(fn () => $from->transitionTo($to))->toThrow(InvalidTransition::class);
    }
})->with('every status pair');

it('never allows a status to transition to itself', function (S $status): void {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(S::cases());

it('reports the allowed targets in the error', function (): void {
    try {
        S::Closed->transitionTo(S::Pending);
        $this->fail('Expected InvalidTransition.');
    } catch (InvalidTransition $e) {
        expect($e->getMessage())->toBe('A ticket cannot move from closed to pending.')
            ->and($e->meta())->toBe(['from' => 'closed', 'to' => 'pending', 'allowed' => ['in_progress']])
            ->and($e->status())->toBe(422)
            ->and($e->code()->value)->toBe('invalid_transition');
    }
});

it('treats resolved and closed as finished', function (): void {
    expect(array_map(fn (S $s) => $s->value, S::active()))->toBe(['open', 'assigned', 'in_progress', 'pending']);
});

it('ranks priorities with P1 as the most urgent', function (): void {
    expect(Priority::P1->rank())->toBe(1)->and(Priority::P4->rank())->toBe(4);
});
