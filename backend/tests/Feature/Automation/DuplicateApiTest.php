<?php

declare(strict_types=1);

use App\Modules\Automation\Actions\PersistDuplicateSuggestions;
use App\Modules\Automation\Actions\SuggestDuplicates;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use App\Modules\Tickets\Models\TicketDuplicateSuggestion;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * Duplicate integration (roadmap M2-10, docs/05-algorithms/duplicate-detection.md). The strategy
 * itself is unit-tested; these tests cover candidates, persistence, the API and the rules around
 * marking a duplicate.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-18 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
    Notification::fake();
    // Assignment is another task's concern; without agents it would only add history noise.
    config(['helpdesk.automation.assignment.enabled' => false]);

    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, 'manager');
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
});

function existingTicket(string $title, string $description, array $attributes = [], ?Tenant $tenant = null): Ticket
{
    if ($tenant !== null) {
        [$contact, $category] = ticketPrerequisites($tenant);
    }

    return Ticket::factory()
        ->forContact($contact ?? test()->contact, $category ?? test()->category)
        ->create(['title' => $title, 'description' => $description, 'created_at' => '2026-09-17 09:00:00', ...$attributes]);
}

function createViaApi(string $title, string $description): string
{
    return test()->postJson('/v1/tickets', [
        'title' => $title, 'description' => $description,
        'contact_id' => test()->contact->id, 'category_id' => test()->category->id,
        'impact' => 1, 'urgency' => 1,
    ])->assertCreated()->json('data.id');
}

const NEW_TITLE = 'Cannot login after password reset';
const NEW_BODY = 'Login page shows error ERR-401 after I reset my password.';

it('reproduces the worked example through the preview endpoint', function (): void {
    $similar = existingTicket('Login fails after resetting password', 'Error ERR-401 on login page.', ['number' => 1031]);
    existingTicket('Invoice PDF is blank', 'The invoice download shows an empty page.', ['number' => 1002]);

    $this->postJson('/v1/tickets/preview-duplicates', ['title' => NEW_TITLE, 'description' => NEW_BODY])
        ->assertOk()
        ->assertJsonPath('data.strategy', 'jaccard_duplicates')
        ->assertJsonPath('data.candidates_compared', 2)
        ->assertJsonCount(1, 'data.matches')
        ->assertJsonPath('data.matches.0.ticket_id', $similar->id)
        ->assertJsonPath('data.matches.0.number', 1031)
        ->assertJsonPath('data.matches.0.score', 0.5)
        ->assertJsonPath('data.matches.0.shared_words', ['err-401', 'error', 'login', 'page', 'password']);
});

it('never offers closed tickets, old tickets or tickets of another workspace as candidates', function (): void {
    existingTicket('Login fails after resetting password', 'Error ERR-401 on login page.', ['status' => TicketStatus::Closed, 'resolved_at' => '2026-09-17 10:00:00', 'closed_at' => '2026-09-17 10:00:00']);
    existingTicket('Login fails after resetting password', 'Error ERR-401 on login page.', ['created_at' => '2026-08-01 09:00:00']);
    existingTicket('Login fails after resetting password', 'Error ERR-401 on login page.', [], $this->globex);

    $this->postJson('/v1/tickets/preview-duplicates', ['title' => NEW_TITLE, 'description' => NEW_BODY])
        ->assertOk()
        ->assertJsonPath('data.candidates_compared', 0)
        ->assertJsonCount(0, 'data.matches');
});

it('stores suggestions with their explanation when a ticket is created', function (): void {
    $similar = existingTicket('Login fails after resetting password', 'Error ERR-401 on login page.');

    $id = createViaApi(NEW_TITLE, NEW_BODY);
    $suggestion = TicketDuplicateSuggestion::query()->withoutTenancy()->where('ticket_id', $id)->sole();

    expect($suggestion->candidate_ticket_id)->toBe($similar->id)
        ->and($suggestion->tenant_id)->toBe($this->acme->id)
        ->and((float) $suggestion->score)->toBe(0.5)
        ->and($suggestion->decision)->toBe('pending')
        ->and($suggestion->breakdown['strategy'])->toBe('jaccard_duplicates')
        ->and($suggestion->breakdown['shared_words'])->toContain('err-401')
        ->and(TicketEvent::query()->withoutTenancy()->where('ticket_id', $id)->where('type', 'duplicate_suggested')->count())->toBe(1);

    $this->getJson("/v1/tickets/{$id}/duplicates")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.candidate_ticket_id', $similar->id)
        ->assertJsonPath('data.0.score', 0.5)
        ->assertJsonPath('data.0.shared_words.0', 'err-401');
});

it('marks a duplicate: closes the ticket, accepts the suggestion and notes the original', function (): void {
    $original = existingTicket('Login fails after resetting password', 'Error ERR-401 on login page.');
    $id = createViaApi(NEW_TITLE, NEW_BODY);

    $this->postJson("/v1/tickets/{$id}/mark-duplicate", ['candidate_ticket_id' => $original->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.duplicate_of_id', $original->id);

    $note = TicketComment::query()->withoutTenancy()->where('ticket_id', $original->id)->sole();
    $ticket = Ticket::query()->withoutTenancy()->findOrFail($id);

    expect(TicketDuplicateSuggestion::query()->withoutTenancy()->where('ticket_id', $id)->sole()->decision)->toBe('accepted')
        ->and($note->visibility)->toBe('internal')
        ->and($note->body)->toContain("#{$ticket->number}")
        ->and($ticket->closed_at)->not->toBeNull()
        ->and(TicketEvent::query()->withoutTenancy()->where('ticket_id', $id)->where('type', 'duplicate_marked')->count())->toBe(1);
});

it('keeps duplicates at depth one in both directions', function (): void {
    $a = existingTicket('Printer A', 'x');
    $b = existingTicket('Printer B', 'x');
    $c = existingTicket('Printer C', 'x');

    $this->postJson("/v1/tickets/{$b->id}/mark-duplicate", ['candidate_ticket_id' => $a->id])->assertOk();

    // B is already a duplicate, so it cannot be a target.
    $this->postJson("/v1/tickets/{$c->id}/mark-duplicate", ['candidate_ticket_id' => $b->id])
        ->assertStatus(422)->assertJsonPath('code', 'duplicate_target_invalid');
    // A already has a duplicate, so it cannot become one: B would end up at depth two.
    $this->postJson("/v1/tickets/{$a->id}/mark-duplicate", ['candidate_ticket_id' => $c->id])
        ->assertStatus(422)->assertJsonPath('code', 'duplicate_target_invalid');
    // A ticket is never its own duplicate.
    $this->postJson("/v1/tickets/{$c->id}/mark-duplicate", ['candidate_ticket_id' => $c->id])->assertStatus(422);
});

it('refuses targets of another workspace and tickets that are no longer open', function (): void {
    $mine = existingTicket('Printer A', 'x');
    $busy = existingTicket('Printer B', 'x', ['status' => TicketStatus::InProgress]);
    $foreign = existingTicket('Printer A', 'x', [], $this->globex);

    $this->postJson("/v1/tickets/{$mine->id}/mark-duplicate", ['candidate_ticket_id' => $foreign->id])->assertNotFound();
    $this->postJson("/v1/tickets/{$busy->id}/mark-duplicate", ['candidate_ticket_id' => $mine->id])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
});

it('cannot reopen a ticket that was closed as a duplicate', function (): void {
    $original = existingTicket('Printer A', 'x');
    $duplicate = existingTicket('Printer A again', 'x');
    $this->postJson("/v1/tickets/{$duplicate->id}/mark-duplicate", ['candidate_ticket_id' => $original->id])->assertOk();

    actingAsRole($this->acme, 'owner', $this->user);
    $this->postJson("/v1/tickets/{$duplicate->id}/transition", ['status' => 'in_progress'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_transition')
        ->assertJsonPath('meta.reason', 'closed_as_duplicate');
});

it('dismisses a suggestion, keeps the decision on re-runs, and refuses to dismiss an accepted one', function (): void {
    $similar = existingTicket('Login fails after resetting password', 'Error ERR-401 on login page.');
    $id = createViaApi(NEW_TITLE, NEW_BODY);

    $this->postJson("/v1/tickets/{$id}/duplicates/{$similar->id}/dismiss")
        ->assertOk()->assertJsonPath('data.decision', 'dismissed');

    // Re-running the suggestion step must not resurrect or reset the decision.
    $ticket = Ticket::query()->withoutTenancy()->findOrFail($id);
    $this->acme->run(function () use ($ticket): void {
        $result = app(SuggestDuplicates::class)(new TicketText(
            $ticket->id, $ticket->title, $ticket->description, $ticket->created_at,
        ));
        app(PersistDuplicateSuggestions::class)($ticket, $result);
    });
    expect(TicketDuplicateSuggestion::query()->withoutTenancy()->where('ticket_id', $id)->sole()->decision)->toBe('dismissed');

    $other = existingTicket('Printer A', 'x');
    $dup = existingTicket('Printer A again', 'x');
    $this->postJson("/v1/tickets/{$dup->id}/mark-duplicate", ['candidate_ticket_id' => $other->id])->assertOk();
    $this->postJson("/v1/tickets/{$dup->id}/duplicates/{$other->id}/dismiss")
        ->assertStatus(409)->assertJsonPath('code', 'already_decided');

    $this->postJson("/v1/tickets/{$id}/duplicates/not-a-uuid/dismiss")->assertNotFound();
});

it('enforces permissions and the preview rate limit', function (): void {
    $original = existingTicket('Printer A', 'x');
    $duplicate = existingTicket('Printer A again', 'x');

    actingAsRole($this->acme, 'developer', createTenantUser($this->acme));
    $this->postJson('/v1/tickets/preview-duplicates', ['title' => 'a', 'description' => 'b'])->assertForbidden();
    $this->postJson("/v1/tickets/{$duplicate->id}/mark-duplicate", ['candidate_ticket_id' => $original->id])->assertForbidden();
    $this->getJson("/v1/tickets/{$duplicate->id}/duplicates")->assertOk();

    $agent = actingAsRole($this->acme, 'agent', createTenantUser($this->acme));
    RateLimiter::clear('duplicate-preview:'.$agent->id);
    foreach (range(1, 30) as $ignored) {
        $this->postJson('/v1/tickets/preview-duplicates', ['title' => 'a b c', 'description' => 'd e f'])->assertOk();
    }
    $this->postJson('/v1/tickets/preview-duplicates', ['title' => 'a b c', 'description' => 'd e f'])
        ->assertStatus(429)->assertJsonPath('code', 'rate_limited');
});
