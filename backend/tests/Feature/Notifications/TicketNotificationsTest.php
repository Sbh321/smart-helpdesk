<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Automation\Events\NoEligibleAgent;
use App\Modules\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Notifications\Models\Notification as StoredNotification;
use App\Modules\Notifications\Notifications\InternalNoteOnYourTicket;
use App\Modules\Notifications\Notifications\PublicReplyOnYourTicket;
use App\Modules\Notifications\Notifications\SlaBreachNotice;
use App\Modules\Notifications\Notifications\SlaWarningNotice;
use App\Modules\Notifications\Notifications\TicketAssignedToYou;
use App\Modules\Notifications\Notifications\TicketEscalated;
use App\Modules\Notifications\Notifications\TicketUnassignable;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// docs/04-domain/notifications.md §Events that notify: who gets what, on which channels.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 09:00:00'));
    $this->tenant = createTenant('notify', ['timezone' => 'UTC']);
    $this->manager = actingAsRole($this->tenant, 'manager');
    $this->agentUser = createTenantUser($this->tenant);
    $this->tenant->run(function (): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $this->agentUser->assignRole('agent');
    });
    $this->agent = AgentProfile::factory()->forTenant($this->tenant)->create(['user_id' => $this->agentUser->id, 'capacity' => 5, 'availability' => 'available']);
    $this->ticket = Ticket::factory()->forTenant($this->tenant)->create(['assigned_agent_id' => $this->agent->id]);
    tenancy()->initialize($this->tenant);
});

it('notifies the agent in-app, by mail and realtime when a manager assigns a ticket', function (): void {
    Notification::fake();
    $ticket = Ticket::factory()->forTenant($this->tenant)->create();
    tenancy()->initialize($this->tenant);

    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $this->agent->id])->assertOk();

    Notification::assertSentTo($this->agentUser, TicketAssignedToYou::class, function (TicketAssignedToYou $notification, array $channels) use ($ticket): bool {
        return $channels === [TenantDatabaseChannel::class, 'broadcast', 'mail']
            && $notification->ticketId === $ticket->id
            && $notification->queue === 'notifications'
            && str_starts_with($notification->key(), 'ticket_assigned:');
    });
    Notification::assertNotSentTo($this->manager, TicketAssignedToYou::class);
});

it('does not notify an agent who takes the ticket themselves', function (): void {
    Notification::fake();
    $this->tenant->run(fn () => $this->agentUser->givePermissionTo('tickets.assign'));
    actingAsTenantUser($this->tenant, $this->agentUser);
    $ticket = Ticket::factory()->forTenant($this->tenant)->create();
    tenancy()->initialize($this->tenant);

    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $this->agent->id])->assertOk();

    Notification::assertNothingSentTo($this->agentUser);
});

it('tells the assignee about replies and notes of others, in-app only', function (string $visibility, string $class): void {
    Notification::fake();

    $this->postJson("/v1/tickets/{$this->ticket->id}/comments", ['body' => 'Any news on this?', 'visibility' => $visibility])->assertCreated();

    Notification::assertSentTo($this->agentUser, $class, fn ($notification, array $channels): bool => $channels === [TenantDatabaseChannel::class, 'broadcast']);
    Notification::assertNothingSentTo($this->manager);
})->with([['public', PublicReplyOnYourTicket::class], ['internal', InternalNoteOnYourTicket::class]]);

it('does not tell the assignee about their own comment', function (): void {
    Notification::fake();
    actingAsTenantUser($this->tenant, $this->agentUser);
    tenancy()->initialize($this->tenant);

    $this->postJson("/v1/tickets/{$this->ticket->id}/comments", ['body' => 'Working on it.', 'visibility' => 'public'])->assertCreated();

    Notification::assertNothingSentTo($this->agentUser);
});

it('sends an SLA warning to the assignee, or to the team when nobody is assigned', function (): void {
    Notification::fake();
    event(new SlaWarning($this->tenant->id, $this->ticket->id, (string) Str::uuid7()));
    Notification::assertSentTo($this->agentUser, SlaWarningNotice::class, fn ($n, array $channels): bool => ! in_array('mail', $channels, true));
    Notification::assertNothingSentTo($this->manager);

    Notification::fake();
    $teamMate = createTenantUser($this->tenant);
    $mate = AgentProfile::factory()->forTenant($this->tenant)->create(['user_id' => $teamMate->id]);
    $team = Team::factory()->forTenant($this->tenant)->create();
    $this->tenant->run(fn () => $team->agents()->attach($mate->id, ['tenant_id' => $this->tenant->id, 'joined_at' => now()]));
    $unassigned = Ticket::factory()->forTenant($this->tenant)->create(['team_id' => $team->id, 'assigned_agent_id' => null]);
    tenancy()->initialize($this->tenant);

    event(new SlaWarning($this->tenant->id, $unassigned->id, (string) Str::uuid7()));

    Notification::assertSentTo($teamMate, SlaWarningNotice::class);
    Notification::assertNothingSentTo($this->agentUser);
});

it('sends an SLA breach to the assignee and the managers, with mail', function (): void {
    Notification::fake();

    event(new SlaBreached($this->tenant->id, $this->ticket->id, (string) Str::uuid7()));

    foreach ([$this->agentUser, $this->manager] as $user) {
        Notification::assertSentTo($user, SlaBreachNotice::class, fn ($n, array $channels): bool => in_array('mail', $channels, true));
    }
});

it('reports a raised priority as an escalation and ignores a lowered one', function (): void {
    Notification::fake();
    event(new PriorityChanged($this->tenant->id, $this->ticket->id, 'P1', 'P3'));
    Notification::assertNothingSent();

    event(new PriorityChanged($this->tenant->id, $this->ticket->id, 'P3', 'P1'));
    Notification::assertSentTo([$this->agentUser, $this->manager], TicketEscalated::class);
});

it('tells the managers when no agent is eligible', function (): void {
    Notification::fake();

    event(new NoEligibleAgent($this->tenant->id, $this->ticket->id, null, []));

    Notification::assertSentTo($this->manager, TicketUnassignable::class);
    Notification::assertNothingSentTo($this->agentUser);
});

it('never notifies inactive users or users of another workspace', function (): void {
    Notification::fake();
    $gone = createTenantUser($this->tenant, ['is_active' => false]);
    $this->tenant->run(function () use ($gone): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $gone->assignRole('manager');
    });
    $foreignManager = actingAsRole(createTenant('notify-other'), 'manager');
    tenancy()->initialize($this->tenant);

    event(new SlaBreached($this->tenant->id, $this->ticket->id, (string) Str::uuid7()));

    Notification::assertNothingSentTo($gone);
    Notification::assertNothingSentTo($foreignManager);
    Notification::assertSentTo($this->manager, SlaBreachNotice::class);
});

it('stores one row per user and occurrence, with the tenant, a v7 id and clock time, also when delivered twice', function (): void {
    $timer = (string) Str::uuid7();

    event(new SlaBreached($this->tenant->id, $this->ticket->id, $timer));
    event(new SlaBreached($this->tenant->id, $this->ticket->id, $timer)); // the sweep retried

    $rows = StoredNotification::query()->withoutTenancy()->where('type', 'sla_breached')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('notifiable_id')->sort()->values()->all())->toBe(collect([$this->agentUser->id, $this->manager->id])->sort()->values()->all())
        ->and($rows->pluck('notification_key')->unique()->all())->toBe(["sla_breached:{$timer}"])
        ->and($rows[0]->tenant_id)->toBe($this->tenant->id)
        ->and(Str::isUuid($rows[0]->id, 7))->toBeTrue()
        ->and($rows[0]->created_at->toDateTimeString())->toBe('2026-09-21 09:00:00')
        ->and($rows[0]->data)->toEqual([
            'kind' => 'sla_breached', 'ticket_id' => $this->ticket->id, 'ticket_number' => $this->ticket->number,
            'ticket_title' => $this->ticket->title, 'summary' => 'A ticket has breached its SLA',
        ]);
});

it('mails a link to the ticket in the workspace', function (): void {
    $mail = (new SlaBreachNotice('Notify Desk', 'notify', $this->ticket->id, 42, 'Printer offline', 'timer'))->toMail($this->agentUser);

    expect($mail->subject)->toBe('[Notify Desk] #42 A ticket has breached its SLA')
        ->and($mail->actionUrl)->toBe('https://'.config('helpdesk.hosts.app')."/notify/tickets/{$this->ticket->id}")
        ->and($mail->salutation)->toBe('Notify Desk');
});

it('broadcasts on the private channel of the user', function (): void {
    expect($this->agentUser->receivesBroadcastNotificationsOn())->toBe("tenants.{$this->tenant->id}.users.{$this->agentUser->id}")
        ->and((new SlaBreachNotice('Notify Desk', 'notify', $this->ticket->id, 42, 'Printer offline', 'timer'))->broadcastType())->toBe('sla_breached');
});
