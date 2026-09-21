<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Contacts\Events\ContactSaved;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Identity\Support\PermissionCatalogue;
use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Jobs\DeliverWebhook;
use App\Modules\Integrations\Listeners\DispatchWebhookEvent;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Events\TicketUpdated;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/WebhookTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * DispatchWebhookEvent: every domain event lands on its catalogue event(s) with the documented
 * `data` (docs/07-api/webhooks.md §Event catalogue), only for subscriptions that listen to it, only
 * inside the event's workspace, and never for internal notes.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->clock = new FrozenClock('2026-09-21 10:00:00');
    $this->app->instance(Clock::class, $this->clock);
    fakeWebhookDns();
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->webhook = createWebhook($this->acme, [
        'ticket.created', 'ticket.updated', 'ticket.assigned', 'ticket.status_changed', 'ticket.priority_changed',
        'ticket.resolved', 'ticket.closed', 'ticket.comment_added', 'ticket.sla_breached', 'contact.created', 'contact.updated',
    ]);
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
    $this->ticket = Ticket::factory()->forContact($this->contact, $this->category)->create(['status' => TicketStatus::Resolved, 'resolved_at' => '2026-09-21 09:00:00']);
});

/**
 * @return list<array<string, mixed>> payloads of the acme deliveries, oldest first
 */
function deliveredPayloads(): array
{
    return test()->acme->run(fn (): array => WebhookDelivery::query()->orderBy('created_at')->orderBy('id')
        ->get()->map(fn (WebhookDelivery $delivery): array => $delivery->payload)->all());
}

it('maps each domain event to its catalogue event and data', function (Closure $raise, array $types, Closure $check): void {
    $this->acme->run(fn () => $raise($this));

    $payloads = deliveredPayloads();
    expect(array_column($payloads, 'type'))->toBe($types);

    foreach ($payloads as $payload) {
        expect($payload['api_version'])->toBe('v1')
            ->and($payload['tenant_id'])->toBe($this->acme->id)
            ->and($payload['occurred_at'])->toBe('2026-09-21T10:00:00Z')
            ->and(array_keys($payload))->toBe(['id', 'type', 'api_version', 'tenant_id', 'occurred_at', 'data']);
        $check($this, $payload['data']);
    }

    Queue::assertPushed(DeliverWebhook::class, count($types));
    expect($this->acme->run(fn (): array => WebhookDelivery::query()->pluck('state')->unique()->all()))->toBe($types === [] ? [] : [DeliveryState::Pending]);
})->with([
    'ticket created' => [
        // Called directly: the real TicketCreated hook also runs automatic assignment.
        fn ($t) => app(DispatchWebhookEvent::class)->ticketCreated(new TicketCreated($t->acme->id, $t->ticket->id)),
        ['ticket.created'],
        fn ($t, array $data) => expect($data['ticket']['id'])->toBe($t->ticket->id)->and($data['ticket']['contact']['id'])->toBe($t->contact->id),
    ],
    'ticket updated' => [
        fn ($t) => event(new TicketUpdated($t->acme->id, $t->ticket->id, null, ['title' => ['old' => 'a', 'new' => 'b']])),
        ['ticket.updated'],
        fn ($t, array $data) => expect($data['changes'])->toBe(['title' => ['old' => 'a', 'new' => 'b']])->and($data['ticket']['id'])->toBe($t->ticket->id),
    ],
    'ticket assigned' => [
        function ($t) {
            $agent = AgentProfile::factory()->forTenant($t->acme)->create();
            $t->agentId = $agent->id;
            event(new TicketAssigned($t->acme->id, $t->ticket->id, $agent->id));
        },
        ['ticket.assigned'],
        fn ($t, array $data) => expect($data['assignment']['agent_id'])->toBe($t->agentId)->and($data)->toHaveKey('ticket'),
    ],
    'status changed to resolved' => [
        fn ($t) => event(new TicketStatusChanged($t->acme->id, $t->ticket->id, null, 'in_progress', 'resolved')),
        ['ticket.status_changed', 'ticket.resolved'],
        fn ($t, array $data) => expect($data['ticket']['status'])->toBe('resolved'),
    ],
    'status changed to closed' => [
        fn ($t) => event(new TicketStatusChanged($t->acme->id, $t->ticket->id, null, 'resolved', 'closed')),
        ['ticket.status_changed', 'ticket.closed'],
        fn ($t, array $data) => expect($data['ticket']['id'])->toBe($t->ticket->id),
    ],
    'status changed to pending' => [
        fn ($t) => event(new TicketStatusChanged($t->acme->id, $t->ticket->id, null, 'in_progress', 'pending')),
        ['ticket.status_changed'],
        fn ($t, array $data) => expect([$data['from'], $data['to']])->toBe(['in_progress', 'pending']),
    ],
    'priority changed' => [
        fn ($t) => event(new PriorityChanged($t->acme->id, $t->ticket->id, 'P3', 'P1')),
        ['ticket.priority_changed'],
        fn ($t, array $data) => expect([$data['from'], $data['to'], $data['reason']])->toBe(['P3', 'P1', 'automatic']),
    ],
    'public comment' => [
        function ($t) {
            $comment = TicketComment::factory()->forTenant($t->acme)->create(['ticket_id' => $t->ticket->id, 'visibility' => 'public']);
            $t->commentId = $comment->id;
            event(new CommentAdded($t->acme->id, $t->ticket->id, $comment->id, 'public', 'user'));
        },
        ['ticket.comment_added'],
        fn ($t, array $data) => expect($data['comment']['id'])->toBe($t->commentId)->and($data['ticket_id'])->toBe($t->ticket->id),
    ],
    'internal note is never delivered' => [
        function ($t) {
            $comment = TicketComment::factory()->forTenant($t->acme)->create(['ticket_id' => $t->ticket->id, 'visibility' => 'internal']);
            event(new CommentAdded($t->acme->id, $t->ticket->id, $comment->id, 'internal', 'user'));
        },
        [],
        fn () => null,
    ],
    'sla breached' => [
        function ($t) {
            $timer = TicketSlaTimer::factory()->forTenant($t->acme)->create([
                'ticket_id' => $t->ticket->id, 'state' => 'breached', 'breached_at' => '2026-09-21 09:59:00',
            ]);
            $t->timerId = $timer->id;
            event(new SlaBreached($t->acme->id, $t->ticket->id, $timer->id));
        },
        ['ticket.sla_breached'],
        fn ($t, array $data) => expect($data['timer']['id'])->toBe($t->timerId)
            ->and($data['timer']['kind'])->toBe('first_response')
            ->and($data['timer']['breached_at'])->toBe('2026-09-21T09:59:00Z'),
    ],
    'contact created' => [
        fn ($t) => event(new ContactSaved($t->acme->id, $t->contact->id, true)),
        ['contact.created'],
        fn ($t, array $data) => expect($data['contact']['id'])->toBe($t->contact->id),
    ],
    'contact updated' => [
        fn ($t) => event(new ContactSaved($t->acme->id, $t->contact->id, false)),
        ['contact.updated'],
        fn ($t, array $data) => expect($data['contact']['email'])->toBe($t->contact->email),
    ],
]);

it('only fans out to active subscriptions that listen to the event', function (): void {
    $onlyCreated = createWebhook($this->acme, ['ticket.created']);
    createWebhook($this->acme, ['ticket.status_changed'], ['is_active' => false]);
    createWebhook($this->globex, ['ticket.status_changed', 'ticket.resolved']);

    $this->acme->run(fn () => event(new TicketStatusChanged($this->acme->id, $this->ticket->id, null, 'in_progress', 'resolved')));

    $deliveries = $this->acme->run(fn () => WebhookDelivery::query()->get());
    expect($deliveries)->toHaveCount(2)
        ->and($this->globex->run(fn (): int => WebhookDelivery::query()->count()))->toBe(0)
        ->and($deliveries->pluck('subscription_id')->unique()->all())->toBe([$this->webhook->id])
        ->and($deliveries->pluck('tenant_id')->unique()->all())->toBe([$this->acme->id])
        // Every delivery of one event shares its id, so receivers can deduplicate.
        ->and($deliveries->pluck('event_id')->unique())->toHaveCount(2)
        ->and($this->acme->run(fn (): int => $onlyCreated->deliveries()->count()))->toBe(0);
});

it('emits contact.created and contact.updated from the contacts API', function (): void {
    actingAsRole($this->acme, PermissionCatalogue::ADMIN);

    $id = $this->postJson('/v1/contacts', ['name' => 'Priya', 'email' => 'priya@example.test'])->assertCreated()->json('data.id');
    $this->patchJson("/v1/contacts/{$id}", ['name' => 'Priya S.'])->assertOk();
    // An edit that changes nothing emits nothing.
    $this->patchJson("/v1/contacts/{$id}", ['name' => 'Priya S.'])->assertOk();

    $payloads = deliveredPayloads();
    expect(array_column($payloads, 'type'))->toBe(['contact.created', 'contact.updated'])
        ->and($payloads[1]['data']['contact']['name'])->toBe('Priya S.')
        ->and(Contact::query()->whereKey($id)->exists())->toBeTrue();
});

it('emits ticket.status_changed and ticket.resolved when a ticket is resolved through the API', function (): void {
    $user = actingAsRole($this->acme, PermissionCatalogue::AGENT);
    $agent = AgentProfile::factory()->forTenant($this->acme)->create(['user_id' => $user->id]);
    $ticket = Ticket::factory()->forContact($this->contact, $this->category)->create([
        'status' => TicketStatus::InProgress, 'assigned_agent_id' => $agent->id,
    ]);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'resolved', 'comment' => 'Fixed.'])->assertOk();

    $types = array_column(deliveredPayloads(), 'type');
    expect($types)->toContain('ticket.status_changed', 'ticket.resolved', 'ticket.comment_added')
        ->and(collect(deliveredPayloads())->firstWhere('type', 'ticket.resolved')['data']['ticket']['status'])->toBe('resolved');
});
