<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Identity\Support\PermissionCatalogue;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-18 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, PermissionCatalogue::AGENT);
    $this->agentProfile = AgentProfile::factory()->forTenant($this->acme)->create(['user_id' => $this->user->id]);
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
});

function lifecycleTicket(TicketStatus $status = TicketStatus::Open, array $attributes = []): Ticket
{
    $resolvedAt = $status->isActive() ? null : '2026-09-17 09:00:00';

    return Ticket::factory()
        ->forContact(test()->contact, test()->category)
        ->create([
            'status' => $status,
            'resolved_at' => $resolvedAt,
            'closed_at' => $status === TicketStatus::Closed ? '2026-09-17 10:00:00' : null,
            'assigned_agent_id' => in_array($status, [TicketStatus::Assigned, TicketStatus::InProgress, TicketStatus::Pending], true)
                ? test()->agentProfile->id
                : null,
            ...$attributes,
        ]);
}

it('edits only documented fields and records the changed values at the application clock time', function (): void {
    $ticket = lifecycleTicket(attributes: [
        'impact' => 1,
        'urgency' => 1,
        'priority_explanation' => ['strategy' => 'baseline', 'version' => '1.0.0', 'parts' => []],
        'priority_override_reason' => 'VIP outage',
    ]);
    $originalTitle = $ticket->title;
    $newCategory = Category::factory()->forTenant($this->acme)->create();

    $this->patchJson("/v1/tickets/{$ticket->id}", [
        'title' => 'Updated printer incident',
        'description' => 'The printer now displays error E-35.',
        'category_id' => $newCategory->id,
        'impact' => 4,
        'urgency' => 3,
        'tags' => ['Hardware', 'Office'],
        'status' => 'closed',
        'assigned_agent_id' => null,
    ])->assertOk()
        ->assertJsonPath('data.title', 'Updated printer incident')
        ->assertJsonPath('data.category_id', $newCategory->id)
        ->assertJsonPath('data.impact', 4)
        ->assertJsonPath('data.urgency', 3)
        ->assertJsonPath('data.status', 'open')
        // Impact and urgency changed, so Automation rescored the ticket inside the edit.
        ->assertJsonPath('data.priority_explanation.strategy', 'basic_weighted_priority')
        ->assertJsonPath('data.priority_override_reason', 'VIP outage')
        ->assertJsonPath('data.tags.0.slug', 'hardware')
        ->assertJsonPath('data.tags.1.slug', 'office');

    $ticket->refresh();
    $event = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'edited')->sole();

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->assigned_agent_id)->toBeNull()
        ->and($ticket->updated_at->toIso8601String())->toBe('2026-09-18T09:00:00+00:00')
        ->and($event->type)->toBe('edited')
        ->and($event->actor_id)->toBe($this->user->id)
        ->and($event->old_values)->toMatchArray(['title' => $originalTitle])
        ->and($event->new_values)->toMatchArray([
            'title' => 'Updated printer incident',
            'category_id' => $newCategory->id,
            'impact' => 4,
            'urgency' => 3,
            'tags' => ['Hardware', 'Office'],
        ])
        ->and($event->created_at->toIso8601String())->toBe('2026-09-18T09:00:00+00:00');
});

it('validates editable fields', function (array $payload, string $field): void {
    $ticket = lifecycleTicket();

    $this->patchJson("/v1/tickets/{$ticket->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'blank title' => [['title' => ''], 'title'],
    'description too long' => [['description' => str_repeat('x', 20001)], 'description'],
    'impact below range' => [['impact' => 0], 'impact'],
    'urgency above range' => [['urgency' => 5], 'urgency'],
    'too many tags' => [['tags' => array_fill(0, 21, 'tag')], 'tags'],
]);

it('keeps editable category lookup inside the workspace', function (): void {
    $ticket = lifecycleTicket();
    $foreignCategory = Category::factory()->forTenant($this->globex)->create();

    $this->patchJson("/v1/tickets/{$ticket->id}", ['category_id' => $foreignCategory->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['category_id']]);
});

it('returns no edit event when a patch makes no change', function (): void {
    $ticket = lifecycleTicket();

    $this->patchJson("/v1/tickets/{$ticket->id}", ['title' => $ticket->title])->assertOk();

    expect(TicketEvent::query()->where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('executes every lifecycle transition that does not belong to assignment or duplicate actions', function (TicketStatus $from, TicketStatus $to): void {
    $ticket = lifecycleTicket($from);
    $payload = ['status' => $to->value];

    if ($to === TicketStatus::Resolved) {
        $payload['comment'] = 'Issue confirmed fixed with the contact.';
    }

    $this->postJson("/v1/tickets/{$ticket->id}/transition", $payload)
        ->assertOk()
        ->assertJsonPath('data.status', $to->value);

    $event = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'status_changed')->sole();
    expect($event->type)->toBe('status_changed')
        ->and($event->old_values)->toMatchArray(['status' => $from->value])
        ->and($event->new_values)->toMatchArray(['status' => $to->value])
        ->and($event->created_at->toIso8601String())->toBe('2026-09-18T09:00:00+00:00');
})->with([
    'assigned to in progress' => [TicketStatus::Assigned, TicketStatus::InProgress],
    'assigned to pending' => [TicketStatus::Assigned, TicketStatus::Pending],
    'in progress to pending' => [TicketStatus::InProgress, TicketStatus::Pending],
    'pending to in progress' => [TicketStatus::Pending, TicketStatus::InProgress],
    'assigned to resolved' => [TicketStatus::Assigned, TicketStatus::Resolved],
    'in progress to resolved' => [TicketStatus::InProgress, TicketStatus::Resolved],
    'pending to resolved' => [TicketStatus::Pending, TicketStatus::Resolved],
]);

it('refuses assignment and duplicate action edges even though they exist in the domain state table', function (TicketStatus $from, TicketStatus $to): void {
    $ticket = lifecycleTicket($from);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => $to->value])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition');

    expect($ticket->fresh()->status)->toBe($from)
        ->and(TicketEvent::query()->where('ticket_id', $ticket->id)->count())->toBe(0);
})->with([
    'assignment' => [TicketStatus::Open, TicketStatus::Assigned],
    'self assignment' => [TicketStatus::Open, TicketStatus::InProgress],
    'unassign assigned' => [TicketStatus::Assigned, TicketStatus::Open],
    'unassign in progress' => [TicketStatus::InProgress, TicketStatus::Open],
    'close open as duplicate' => [TicketStatus::Open, TicketStatus::Closed],
]);

it('refuses an edge absent from the state table and reports only safe alternatives', function (): void {
    $ticket = lifecycleTicket(TicketStatus::Pending);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'closed'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition')
        ->assertJsonPath('meta.from', 'pending')
        ->assertJsonPath('meta.to', 'closed')
        ->assertJsonPath('meta.allowed', ['in_progress', 'resolved']);
});

it('requires and persists a public resolution comment from the actor', function (): void {
    $ticket = lifecycleTicket(TicketStatus::InProgress);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'resolved'])
        ->assertUnprocessable()
        ->assertJson(['code' => 'resolution_comment_required', 'meta' => ['field' => 'comment']]);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", [
        'status' => 'resolved',
        'comment' => 'Restarted the print spooler and verified a test page.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolved_at', '2026-09-18T09:00:00Z');

    $comment = TicketComment::query()->where('ticket_id', $ticket->id)->sole();
    $event = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'status_changed')->sole();
    expect($comment->visibility)->toBe('public')
        ->and($comment->author_type)->toBe('user')
        ->and($comment->author_id)->toBe($this->user->id)
        ->and($comment->body)->toBe('Restarted the print spooler and verified a test page.')
        ->and($comment->created_at->toIso8601String())->toBe('2026-09-18T09:00:00+00:00')
        ->and($event->note)->toBe('Restarted the print spooler and verified a test page.');
});

it('accepts the latest public agent comment as the resolution comment', function (): void {
    $ticket = lifecycleTicket(TicketStatus::InProgress);
    $comment = new TicketComment([
        'ticket_id' => $ticket->id,
        'visibility' => 'public',
        'author_type' => 'user',
        'author_id' => $this->user->id,
        'body' => 'The fix has already been explained to the contact.',
        'created_at' => '2026-09-18 08:55:00',
        'updated_at' => '2026-09-18 08:55:00',
    ]);
    $comment->tenant_id = $this->acme->id;
    $comment->save();

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');

    expect(TicketComment::query()->where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('does not treat an older agent reply as the resolution when the latest comment is from the contact', function (): void {
    $ticket = lifecycleTicket(TicketStatus::InProgress);

    foreach ([
        ['author_type' => 'user', 'author_id' => $this->user->id, 'body' => 'An earlier agent reply.', 'created_at' => '2026-09-18 08:50:00'],
        ['author_type' => 'contact', 'author_id' => $this->contact->id, 'body' => 'The issue remains.', 'created_at' => '2026-09-18 08:55:00'],
    ] as $attributes) {
        $comment = new TicketComment([
            'ticket_id' => $ticket->id,
            'visibility' => 'public',
            'updated_at' => $attributes['created_at'],
            ...$attributes,
        ]);
        $comment->tenant_id = $this->acme->id;
        $comment->save();
    }

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'resolved'])
        ->assertUnprocessable()
        ->assertJson(['code' => 'resolution_comment_required', 'meta' => ['field' => 'comment']]);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);
});

it('requires target-specific permissions and hides those transitions from the resource', function (): void {
    $user = createTenantUser($this->acme);
    actingAsTenantUser($this->acme, $user);
    $this->acme->run(fn () => $user->givePermissionTo(['tickets.view', 'tickets.update']));

    // Resolved: closing needs tickets.close; reopening a resolved ticket is ordinary agent work.
    $resolved = lifecycleTicket(TicketStatus::Resolved);
    $this->getJson("/v1/tickets/{$resolved->id}")
        ->assertOk()
        ->assertJsonPath('data.allowed_transitions', ['in_progress']);
    $this->postJson("/v1/tickets/{$resolved->id}/transition", ['status' => 'closed'])->assertForbidden();

    // Closed: reopening needs tickets.reopen (docs/04-domain/tickets.md §Transition rules).
    $closed = lifecycleTicket(TicketStatus::Closed);
    $this->getJson("/v1/tickets/{$closed->id}")->assertOk()->assertJsonPath('data.allowed_transitions', []);
    $this->postJson("/v1/tickets/{$closed->id}/transition", ['status' => 'in_progress'])->assertForbidden();
});

it('closes a resolved ticket with the application clock', function (): void {
    $ticket = lifecycleTicket(TicketStatus::Resolved);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.closed_at', '2026-09-18T09:00:00Z');

    expect($ticket->fresh()->resolved_at?->toIso8601String())->toBe('2026-09-17T09:00:00+00:00');
});

it('reopens resolved and closed tickets inside the configured window', function (TicketStatus $from): void {
    $ticket = lifecycleTicket($from, ['reopen_count' => 2]);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.resolved_at', null)
        ->assertJsonPath('data.closed_at', null);

    $ticket->refresh();
    $event = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'reopened')->sole();
    expect($ticket->reopen_count)->toBe(3)
        ->and($event->type)->toBe('reopened');
})->with([TicketStatus::Resolved, TicketStatus::Closed]);

it('refuses reopening after the configured window and omits it from allowed transitions', function (): void {
    config(['helpdesk.tickets.reopen_window_days' => 14]);
    $ticket = lifecycleTicket(TicketStatus::Resolved, ['resolved_at' => '2026-09-04 08:59:59']);

    $this->getJson("/v1/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('data.allowed_transitions', ['closed']);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'in_progress'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition')
        ->assertJsonPath('meta.reason', 'reopen_window_expired');
});

it('returns cross-workspace ticket ids as not found for both write endpoints', function (): void {
    [$foreignContact, $foreignCategory] = ticketPrerequisites($this->globex);
    $foreign = $this->globex->run(fn () => Ticket::factory()->forContact($foreignContact, $foreignCategory)->create());

    $this->patchJson("/v1/tickets/{$foreign->id}", ['title' => 'Cross-workspace edit'])->assertNotFound();
    $this->postJson("/v1/tickets/{$foreign->id}/transition", ['status' => 'assigned'])->assertNotFound();
});

it('forbids a read-only user from both write endpoints', function (): void {
    actingAsRole($this->acme, PermissionCatalogue::DEVELOPER, createTenantUser($this->acme));
    $ticket = lifecycleTicket();

    $this->patchJson("/v1/tickets/{$ticket->id}", ['title' => 'Forbidden'])->assertForbidden();
    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'assigned'])->assertForbidden();
});
