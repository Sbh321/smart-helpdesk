<?php

declare(strict_types=1);

use App\Modules\Identity\Notifications\UserInvitation;
use App\Modules\Notifications\Notifications\TicketAssignedToYou;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Mail\SentMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

// Mail as it leaves the application (array transport, queue runs synchronously): sender identity and
// threading headers (docs/04-domain/email.md §Outbound, M3-18).

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 09:00:00'));
    config(['helpdesk.hosts.mail' => 'shp.example', 'mail.from.address' => 'no-reply@shp.example']);
    $this->acme = createTenant('acme', ['name' => 'Acme']);
});

/** @return list<Email> */
function sentMail(): array
{
    /** @var ArrayTransport $transport */
    $transport = app('mailer')->getSymfonyTransport();

    return array_values(array_map(
        fn (SentMessage|Symfony\Component\Mailer\SentMessage $sent): Email => $sent->getOriginalMessage(),
        iterator_to_array($transport->messages()),
    ));
}

function mailHeader(Email $email, string $name): ?string
{
    return $email->getHeaders()->get($name)?->getBodyAsString();
}

function addresses(array $list): array
{
    return array_map(fn (Address $address): string => $address->toString(), $list);
}

it('sends an agent reply to the requester from the workspace sender with the threading headers', function (): void {
    actingAsRole($this->acme, 'agent');
    [$contact, $category] = ticketPrerequisites($this->acme);
    $ticket = Ticket::factory()->forContact($contact, $category)->create(['status' => TicketStatus::InProgress, 'title' => 'Printer offline']);
    app(Settings::class)->update('email', ['sender_name' => 'Acme Care']);

    $this->postJson("/v1/tickets/{$ticket->id}/comments", ['body' => 'First answer.', 'visibility' => 'public'])->assertCreated();
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 10:00:00'));
    $this->postJson("/v1/tickets/{$ticket->id}/comments", ['body' => 'Second answer.', 'visibility' => 'public'])->assertCreated();

    [$first, $second] = array_values(array_filter(sentMail(), fn (Email $mail): bool => addresses($mail->getTo()) === [addresses([new Address($contact->email, $contact->name)])[0]]));

    $root = "ticket-{$ticket->id}.0@shp.example";
    expect(addresses($first->getFrom()))->toBe(['"Acme Care" <support+acme@shp.example>'])
        ->and(addresses($first->getReplyTo()))->toBe(["\"Acme Care\" <ticket+{$ticket->id}@shp.example>"])
        ->and($first->getSubject())->toBe("[#{$ticket->number}] Printer offline")
        ->and(mailHeader($first, 'Message-ID'))->toBe("<ticket-{$ticket->id}.1@shp.example>")
        ->and(mailHeader($first, 'In-Reply-To'))->toBe("<{$root}>")
        ->and(mailHeader($first, 'References'))->toBe("<{$root}>")
        ->and(mailHeader($first, 'List-Unsubscribe'))->toBe("<mailto:unsubscribe+{$contact->id}@shp.example>")
        ->and(mailHeader($first, 'X-Helpdesk-Ticket'))->toBe($ticket->id)
        ->and(mailHeader($second, 'Message-ID'))->toBe("<ticket-{$ticket->id}.2@shp.example>")
        ->and(mailHeader($second, 'In-Reply-To'))->toBe("<ticket-{$ticket->id}.1@shp.example>")
        ->and(mailHeader($second, 'References'))->toBe("<{$root}> <ticket-{$ticket->id}.1@shp.example>")
        ->and($first->getTextBody())->toStartWith("Acme Care replied to ticket #{$ticket->number}: Printer offline");
});

it('uses "<Workspace> Support" when no sender name is set', function (): void {
    actingAsRole($this->acme, 'agent');
    [$contact, $category] = ticketPrerequisites($this->acme);
    $ticket = Ticket::factory()->forContact($contact, $category)->create(['status' => TicketStatus::InProgress]);

    $this->postJson("/v1/tickets/{$ticket->id}/comments", ['body' => 'Hello.', 'visibility' => 'public'])->assertCreated();

    $mail = collect(sentMail())->first(fn (Email $mail): bool => mailHeader($mail, 'X-Helpdesk-Ticket') === $ticket->id);
    expect(addresses($mail->getFrom()))->toBe(['"Acme Support" <support+acme@shp.example>']);
});

it('sends an invitation from the platform address on behalf of the workspace, without List-Unsubscribe', function (): void {
    $user = createTenantUser($this->acme, ['email' => 'new.agent@acme.test']);

    $user->notify(new UserInvitation('token-1', 'acme', 'Acme'));

    [$mail] = sentMail();
    expect(addresses($mail->getFrom()))->toBe(['"Acme via Smart Helpdesk" <no-reply@shp.example>'])
        ->and(addresses($mail->getTo()))->toContain('new.agent@acme.test')
        ->and($mail->getHeaders()->has('List-Unsubscribe'))->toBeFalse();
});

it('sends an agent notification from the platform address, referencing the ticket thread', function (): void {
    $agentUser = createTenantUser($this->acme, ['email' => 'agent@acme.test']);
    $ticket = Ticket::factory()->forTenant($this->acme)->create();
    tenancy()->initialize($this->acme);

    $agentUser->notify(new TicketAssignedToYou('Acme', 'acme', $ticket->id, $ticket->number, $ticket->title, 'assignment-1'));

    $mail = collect(sentMail())->first(fn (Email $mail): bool => addresses($mail->getTo()) !== [] && str_contains(addresses($mail->getTo())[0], 'agent@acme.test'));
    expect(addresses($mail->getFrom()))->toBe(['"Acme via Smart Helpdesk" <no-reply@shp.example>'])
        ->and(mailHeader($mail, 'References'))->toBe("<ticket-{$ticket->id}.0@shp.example>")
        ->and(mailHeader($mail, 'Auto-Submitted'))->toBe('auto-generated')
        ->and(mailHeader($mail, 'X-Helpdesk-Ticket'))->toBe($ticket->id)
        ->and($mail->getHeaders()->has('List-Unsubscribe'))->toBeFalse()
        ->and($mail->getHeaders()->has('Reply-To'))->toBeFalse();
});

it('sends a test message with the ticket headers through the configured mailer', function (): void {
    $this->artisan('mail:send-test', ['to' => 'ops@example.com', '--workspace' => 'Acme'])
        ->expectsOutputToContain('Sent to ops@example.com through the "array" mailer')
        ->assertSuccessful();

    [$mail] = sentMail();
    expect(addresses($mail->getFrom()))->toBe(['"Acme via Smart Helpdesk" <no-reply@shp.example>'])
        ->and(addresses($mail->getReplyTo())[0])->toStartWith('ticket+')
        ->and(mailHeader($mail, 'Message-ID'))->toEndWith('.1@shp.example>')
        ->and(mailHeader($mail, 'In-Reply-To'))->toEndWith('.0@shp.example>')
        ->and(mailHeader($mail, 'List-Unsubscribe'))->toStartWith('<mailto:unsubscribe+');

    $this->artisan('mail:send-test', ['to' => 'not-an-address'])->assertFailed();
});

it('names only the product when the test message names no workspace', function (): void {
    $this->artisan('mail:send-test', ['to' => 'ops@example.com'])->assertSuccessful();

    [$mail] = sentMail();
    expect(addresses($mail->getFrom()))->toBe(['"Smart Helpdesk" <no-reply@shp.example>']);
});
