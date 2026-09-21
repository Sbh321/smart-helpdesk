<?php

declare(strict_types=1);

use App\Modules\Mail\Notifications\PublicReplyToContact;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-18 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
    Notification::fake();

    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, 'agent');
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
    $this->ticket = Ticket::factory()->forContact($this->contact, $this->category)
        ->create(['status' => TicketStatus::InProgress, 'title' => 'VPN drops every hour']);
});

function addComment(array $payload = []): TestResponse
{
    return test()->postJson('/v1/tickets/'.test()->ticket->id.'/comments', [
        'body' => 'Please restart the VPN client and try again.',
        'visibility' => 'public',
        ...$payload,
    ]);
}

it('stores a public reply, stamps the first response and records history', function (): void {
    addComment()->assertCreated()
        ->assertJsonPath('data.visibility', 'public')
        ->assertJsonPath('data.author_type', 'user');

    $ticket = $this->ticket->fresh();
    $event = TicketEvent::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('type', 'comment_added')->sole();

    expect($ticket->first_responded_at?->toIso8601String())->toBe('2026-09-18T09:00:00+00:00')
        ->and($ticket->last_agent_reply_at)->not->toBeNull()
        ->and($event->new_values['visibility'])->toBe('public');
});

it('keeps the first response time on later replies', function (): void {
    addComment()->assertCreated();
    $this->clock->advance('2 hours');
    addComment(['body' => 'Any news?'])->assertCreated();

    expect($this->ticket->fresh()->first_responded_at?->toIso8601String())->toBe('2026-09-18T09:00:00+00:00');
});

it('mails a public agent reply to the requester with threading headers and an escaped body', function (): void {
    $body = "Hello **there**\n\n<script>alert(1)</script> ![x](http://evil.test/p.png)";
    addComment(['body' => $body])->assertCreated();

    Notification::assertSentOnDemand(PublicReplyToContact::class, function (PublicReplyToContact $mail, array $channels, AnonymousNotifiable $to): bool {
        $message = $mail->toMail($to);
        $email = new Email;
        foreach ($message->callbacks as $callback) {
            $callback($email);
        }
        $headers = $email->getHeaders();
        $html = view($message->view['html'], $message->viewData)->render();

        expect(array_key_first($to->routes['mail']))->toBe($this->contact->email)
            ->and($message->subject)->toBe("[#{$this->ticket->number}] VPN drops every hour")
            ->and($message->replyTo[0][0])->toBe("ticket+{$this->ticket->id}@shp.localhost")
            ->and($headers->get('Message-ID')?->getBodyAsString())->toBe("<ticket-{$this->ticket->id}.1@shp.localhost>")
            ->and($headers->get('In-Reply-To')?->getBodyAsString())->toBe("<ticket-{$this->ticket->id}.0@shp.localhost>")
            ->and($headers->has('List-Unsubscribe'))->toBeTrue()
            // User input is escaped text: no tags, no rendered Markdown image or bold.
            ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($html)->not->toContain('<script>')
            ->and($html)->not->toContain('<img')
            ->and($html)->not->toContain('<strong>');

        return true;
    });
});

it('never mails internal notes or replies recorded for the requester', function (): void {
    actingAsRole($this->acme, 'agent', $this->user);

    addComment(['visibility' => 'internal', 'body' => 'Customer sounds angry.'])->assertCreated();
    addComment(['author_type' => 'contact', 'body' => 'It still fails.'])->assertCreated();

    Notification::assertNothingSent();
    expect($this->ticket->fresh()->first_responded_at)->toBeNull();
});

it('moves a pending ticket back to in progress when the requester replies, and says so in history', function (): void {
    $this->ticket->forceFill(['status' => TicketStatus::Pending, 'pending_since' => $this->clock->now()])->save();

    addComment(['author_type' => 'contact', 'body' => 'Here is the log you asked for.'])->assertCreated();

    $ticket = $this->ticket->fresh();
    $event = TicketEvent::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('type', 'status_changed')->sole();

    expect($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->pending_since)->toBeNull()
        ->and($ticket->last_customer_reply_at)->not->toBeNull()
        ->and($event->actor_type)->toBe('system')
        ->and($event->old_values)->toBe(['status' => 'pending'])
        ->and($event->new_values)->toBe(['status' => 'in_progress']);
});

it('hides internal notes from users without comments.internal and refuses to let them write one', function (): void {
    addComment(['visibility' => 'internal', 'body' => 'Internal only.'])->assertCreated();
    addComment()->assertCreated();

    $this->getJson("/v1/tickets/{$this->ticket->id}/comments")->assertOk()->assertJsonCount(2, 'data');

    // The developer role reads tickets but holds neither comments.internal nor tickets.update.
    actingAsRole($this->acme, 'developer', createTenantUser($this->acme));
    $this->getJson("/v1/tickets/{$this->ticket->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.visibility', 'public');
    addComment(['visibility' => 'internal'])->assertForbidden();
});

it('validates the comment', function (array $payload, string $field): void {
    addComment($payload)->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
})->with([
    'empty body' => [['body' => ''], 'body'],
    'unknown visibility' => [['visibility' => 'secret'], 'visibility'],
    'unknown author type' => [['author_type' => 'robot'], 'author_type'],
]);

it('answers 404 for a ticket of another workspace', function (): void {
    [$contact, $category] = ticketPrerequisites($this->globex);
    $foreign = Ticket::factory()->forContact($contact, $category)->create();

    $this->postJson("/v1/tickets/{$foreign->id}/comments", ['body' => 'x', 'visibility' => 'public'])->assertNotFound();
    $this->getJson("/v1/tickets/{$foreign->id}/comments")->assertNotFound();
});

it('hides internal notes from a bearer token whose user lacks comments.internal', function (): void {
    addComment(['visibility' => 'internal', 'body' => 'Internal only.'])->assertCreated();
    addComment()->assertCreated();

    $integration = createTenantUser($this->acme);
    $this->acme->run(function () use ($integration): void {
        setPermissionsTeamId($this->acme->getTenantKey());
        $integration->syncRoles(['developer']);
    });
    $issued = $integration->createToken('integration');
    $issued->accessToken->forceFill(['tenant_id' => $this->acme->id])->save();
    $token = $issued->plainTextToken;

    auth()->forgetGuards();
    $this->flushSession();
    $this->withToken($token)
        ->getJson("/v1/tickets/{$this->ticket->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.visibility', 'public')
        ->assertJsonMissing(['body' => 'Internal only.']);
});
