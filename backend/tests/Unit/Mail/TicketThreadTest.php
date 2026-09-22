<?php

declare(strict_types=1);

use App\Modules\Mail\Support\TicketThread;
use Symfony\Component\Mime\Header\Headers;

// Header builder for ticket mail (docs/04-domain/email.md §Outbound).

const THREAD_TICKET = '0199aa00-0000-7000-8000-000000000001';
const THREAD_CONTACT = '0199aa00-0000-7000-8000-0000000000c1';

function sampleThread(): TicketThread
{
    return new TicketThread(THREAD_TICKET, 'mail.test');
}

function threadHeader(Headers $headers, string $name): ?string
{
    return $headers->get($name)?->getBodyAsString();
}

it('builds the plus-address, message ids and the unsubscribe address on the mail domain', function (): void {
    expect(sampleThread()->replyTo())->toBe('ticket+'.THREAD_TICKET.'@mail.test')
        ->and(sampleThread()->messageId(0))->toBe('ticket-'.THREAD_TICKET.'.0@mail.test')
        ->and(sampleThread()->messageId(3))->toBe('ticket-'.THREAD_TICKET.'.3@mail.test')
        ->and(sampleThread()->messageId(-2))->toBe('ticket-'.THREAD_TICKET.'.0@mail.test')
        ->and(sampleThread()->unsubscribe(THREAD_CONTACT))->toBe('<mailto:unsubscribe+'.THREAD_CONTACT.'@mail.test>');
});

it('gives the root reply its own id and no references', function (): void {
    $headers = new Headers;
    sampleThread()->applyConversation($headers, 0, THREAD_CONTACT);

    expect(threadHeader($headers, 'Message-ID'))->toBe('<ticket-'.THREAD_TICKET.'.0@mail.test>')
        ->and($headers->has('In-Reply-To'))->toBeFalse()
        ->and($headers->has('References'))->toBeFalse()
        ->and(threadHeader($headers, 'List-Unsubscribe'))->toBe('<mailto:unsubscribe+'.THREAD_CONTACT.'@mail.test>')
        ->and(threadHeader($headers, 'X-Helpdesk-Ticket'))->toBe(THREAD_TICKET);
});

it('threads a later reply to the previous one and the root', function (): void {
    $headers = new Headers;
    sampleThread()->applyConversation($headers, 3, THREAD_CONTACT);

    expect(threadHeader($headers, 'Message-ID'))->toBe('<ticket-'.THREAD_TICKET.'.3@mail.test>')
        ->and(threadHeader($headers, 'In-Reply-To'))->toBe('<ticket-'.THREAD_TICKET.'.2@mail.test>')
        ->and(threadHeader($headers, 'References'))->toBe('<ticket-'.THREAD_TICKET.'.0@mail.test> <ticket-'.THREAD_TICKET.'.2@mail.test>');
});

it('references the root once when the previous reply is the root', function (): void {
    $headers = new Headers;
    sampleThread()->applyConversation($headers, 1);

    expect(threadHeader($headers, 'In-Reply-To'))->toBe('<ticket-'.THREAD_TICKET.'.0@mail.test>')
        ->and(threadHeader($headers, 'References'))->toBe('<ticket-'.THREAD_TICKET.'.0@mail.test>')
        // No contact: not a list the recipient could leave.
        ->and($headers->has('List-Unsubscribe'))->toBeFalse();
});

it('replaces headers that are already there instead of adding a second copy', function (): void {
    $headers = new Headers;
    $headers->addIdHeader('Message-ID', 'generated@host.test');
    $headers->addTextHeader('List-Unsubscribe', '<mailto:old@host.test>');

    sampleThread()->applyConversation($headers, 2, THREAD_CONTACT);
    sampleThread()->applyConversation($headers, 2, THREAD_CONTACT);

    expect(iterator_to_array($headers->all('Message-ID'), false))->toHaveCount(1)
        ->and(iterator_to_array($headers->all('References'), false))->toHaveCount(1)
        ->and(iterator_to_array($headers->all('List-Unsubscribe'), false))->toHaveCount(1)
        ->and(iterator_to_array($headers->all('X-Helpdesk-Ticket'), false))->toHaveCount(1)
        ->and(threadHeader($headers, 'Message-ID'))->toBe('<ticket-'.THREAD_TICKET.'.2@mail.test>');
});

it('makes an agent notification reference the thread root and mark itself auto-generated', function (): void {
    $headers = new Headers;
    $headers->addIdHeader('Message-ID', 'own-id@host.test');

    sampleThread()->applyNotification($headers);

    expect(threadHeader($headers, 'Message-ID'))->toBe('<own-id@host.test>')
        ->and(threadHeader($headers, 'References'))->toBe('<ticket-'.THREAD_TICKET.'.0@mail.test>')
        ->and(threadHeader($headers, 'Auto-Submitted'))->toBe('auto-generated')
        ->and(threadHeader($headers, 'X-Helpdesk-Ticket'))->toBe(THREAD_TICKET)
        ->and($headers->has('List-Unsubscribe'))->toBeFalse()
        ->and($headers->has('In-Reply-To'))->toBeFalse();
});
