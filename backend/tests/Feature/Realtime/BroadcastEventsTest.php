<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\TicketAssignedToYou;
use App\Modules\Realtime\Listeners\BroadcastTicketActivity;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Events\TicketUpdated;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/RealtimeTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * Broadcasts (docs/03-architecture/realtime.md §Events): which domain event becomes which broadcast,
 * on which channels, with which payload; queued after commit on `broadcasts`, only when realtime is
 * on; sent inside the workspace the event came from; a stopped Reverb fails nothing.
 */

beforeEach(function (): void {
    useRecordingBroadcaster();
    $this->acme = createTenant('acme');
    $this->user = actingAsRole($this->acme, 'agent');
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
    $this->ticket = Ticket::factory()->forContact($this->contact, $this->category)->create(['status' => TicketStatus::InProgress]);
});

/** Runs the queued broadcast listeners the way Horizon does, in a fresh central context. */
function runBroadcastQueue(): void
{
    tenancy()->end();
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'broadcasts', '--stop-when-empty' => true, '--memory' => 2048]);
}

it('maps each ticket event to its broadcast, channels and minimal payload', function (string $case, string $name, array $details): void {
    $a = $this->acme->id;
    $t = $this->ticket->id;
    $listener = app(BroadcastTicketActivity::class);

    match ($case) {
        'created' => $listener->created(new TicketCreated($a, $t)),
        'updated' => $listener->updated(new TicketUpdated($a, $t, null, ['title' => 'Secret title', 'impact' => 'high'])),
        'assigned' => $listener->assigned(new TicketAssigned($a, $t, 'agent-1')),
        'status' => $listener->statusChanged(new TicketStatusChanged($a, $t, null, 'open', 'assigned')),
        'priority' => $listener->priorityChanged(new PriorityChanged($a, $t, 'P3', 'P1')),
        'sla' => $listener->slaChanged(new SlaBreached($a, $t, 'timer-1')),
    };

    expect(RecordingBroadcaster::$sent)->toBe([[
        'channels' => ["private-tenants.{$a}.tickets", "private-tenants.{$a}.tickets.{$t}"],
        'event' => $name,
        'payload' => ['ticket_id' => $t, ...$details],
        'tenant' => $a,
    ]]);
})->with([
    'created' => ['created', 'ticket.created', []],
    'updated' => ['updated', 'ticket.updated', ['fields' => ['title', 'impact']]],
    'assigned' => ['assigned', 'ticket.assigned', ['agent_id' => 'agent-1']],
    'status' => ['status', 'ticket.status_changed', ['from' => 'open', 'to' => 'assigned']],
    'priority' => ['priority', 'ticket.priority_changed', ['from' => 'P3', 'to' => 'P1']],
    'sla' => ['sla', 'ticket.updated', ['fields' => ['sla']]],
]);

it('sends public replies on the ticket channel and internal notes only on the internal channel', function (): void {
    $a = $this->acme->id;
    $t = $this->ticket->id;
    $listener = app(BroadcastTicketActivity::class);

    $listener->commented(new CommentAdded($a, $t, 'c-public', 'public', 'user'));
    $listener->commented(new CommentAdded($a, $t, 'c-internal', 'internal', 'user'));

    expect(RecordingBroadcaster::$sent)->toBe([
        ['channels' => ["private-tenants.{$a}.tickets.{$t}"], 'event' => 'comment.added',
            'payload' => ['ticket_id' => $t, 'comment_id' => 'c-public', 'visibility' => 'public'], 'tenant' => $a],
        ['channels' => ["private-tenants.{$a}.tickets.{$t}.internal"], 'event' => 'comment.added',
            'payload' => ['ticket_id' => $t, 'comment_id' => 'c-internal', 'visibility' => 'internal'], 'tenant' => $a],
    ]);
});

it('never carries ticket content in a payload', function (): void {
    $this->patchJson("/v1/tickets/{$this->ticket->id}", ['title' => 'Payroll export leaks salaries'])->assertOk();
    $this->postJson("/v1/tickets/{$this->ticket->id}/comments", ['body' => 'The password is hunter2', 'visibility' => 'internal'])->assertCreated();
    runBroadcastQueue();

    $wire = json_encode(RecordingBroadcaster::$sent, JSON_THROW_ON_ERROR);
    expect(RecordingBroadcaster::$sent)->not->toBeEmpty()
        ->and($wire)->not->toContain('Payroll')->not->toContain('hunter2');
});

it('queues on broadcasts after commit and broadcasts inside the event\'s workspace', function (): void {
    config(['queue.default' => 'database']);

    $this->postJson("/v1/tickets/{$this->ticket->id}/comments", ['body' => 'On it.', 'visibility' => 'public'])->assertCreated();

    $payloads = DB::table('jobs')->where('queue', 'broadcasts')->pluck('payload')
        ->map(fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
    expect($payloads)->not->toBeEmpty()
        ->and($payloads->pluck('tenant_id')->unique()->all())->toBe([$this->acme->id]);

    runBroadcastQueue();

    $comment = collect(RecordingBroadcaster::$sent)->firstWhere('event', 'comment.added');
    expect($comment['channels'])->toBe(["private-tenants.{$this->acme->id}.tickets.{$this->ticket->id}"])
        ->and($comment['tenant'])->toBe($this->acme->id);
});

it('uses the blocking broadcasts connection when the queues run on Redis', function (): void {
    $listener = app(BroadcastTicketActivity::class);

    config(['queue.default' => 'redis']);
    expect($listener->viaConnection())->toBe('redis-broadcasts')->and($listener->viaQueue())->toBe('broadcasts');

    config(['queue.default' => 'database']);
    expect($listener->viaConnection())->toBe('database');
});

it('announces nothing when the change rolls back', function (): void {
    config(['queue.default' => 'database']);

    try {
        DB::transaction(function (): void {
            event(new CommentAdded($this->acme->id, $this->ticket->id, 'c-1', 'public', 'user'));
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('jobs')->where('queue', 'broadcasts')->count())->toBe(0);
});

it('queues nothing without a WebSocket broadcaster or with the workspace flag off', function (string $case): void {
    Queue::fake();
    if ($case === 'log broadcaster') {
        config(['broadcasting.default' => 'log']);
    } else {
        actingAsRole($this->acme, 'admin');
        $this->patchJson('/v1/settings/features', ['realtime' => false, 'exports' => true])->assertOk();
    }

    $this->postJson("/v1/tickets/{$this->ticket->id}/comments", ['body' => 'On it.', 'visibility' => 'public'])->assertCreated();

    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === BroadcastTicketActivity::class);
})->with(['log broadcaster', 'workspace flag off']);

it('refuses to broadcast an event of another workspace from this context', function (): void {
    $globex = createTenant('globex');

    app(BroadcastTicketActivity::class)->created(new TicketCreated($globex->id, $this->ticket->id));

    expect(RecordingBroadcaster::$sent)->toBe([]);
});

it('logs and swallows a failed broadcast', function (): void {
    RecordingBroadcaster::$failing = true;
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => $message === 'realtime.broadcast_failed');

    app(BroadcastTicketActivity::class)->created(new TicketCreated($this->acme->id, $this->ticket->id));

    expect(RecordingBroadcaster::$sent)->toBe([]);
});

it('rings the bell of the notified user on their own channel', function (): void {
    $agent = createTenantUser($this->acme);

    $agent->notify(new TicketAssignedToYou('Acme', 'acme', $this->ticket->id, 7, 'Secret title', 'assignment-1'));

    expect(RecordingBroadcaster::$sent)->toBe([[
        'channels' => ["private-tenants.{$this->acme->id}.users.{$agent->id}"],
        'event' => 'notification.created',
        'payload' => ['kind' => 'ticket_assigned'],
        'tenant' => $this->acme->id,
    ]]);
});
