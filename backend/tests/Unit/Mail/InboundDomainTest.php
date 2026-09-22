<?php

declare(strict_types=1);

use App\Modules\Mail\Domain\AutomatedMailDetector;
use App\Modules\Mail\Domain\EmailAddress;
use App\Modules\Mail\Domain\InboundAddresses;
use App\Modules\Mail\Domain\ParsedAttachment;
use App\Modules\Mail\Domain\ParsedEmail;
use App\Modules\Mail\Domain\RawHeaders;
use App\Modules\Mail\Domain\RouteCandidates;
use App\Modules\Mail\Domain\SubjectLine;
use App\Modules\Mail\Support\ImapInboundMailbox;

// The pure parts of inbound mail (app/Modules/Mail/Domain): 100 % line coverage, like the algorithms.

/**
 * @param  array<string, list<string>>  $headers
 * @param  list<EmailAddress>  $to
 */
function emailWith(array $headers = [], ?EmailAddress $from = null, array $to = [], array $inReplyTo = [], array $references = [], array $cc = []): ParsedEmail
{
    return new ParsedEmail('id@x.test', $from ?? new EmailAddress('someone@x.test'), $to, $cc, 'subject', null, $inReplyTo, $references, $headers, 'text', null);
}

describe('EmailAddress', function (): void {
    it('lower-cases the address and splits it', function (): void {
        $address = new EmailAddress('  Asha.Rai@Wayne-Foods.TEST ', 'Asha');

        expect($address->address)->toBe('asha.rai@wayne-foods.test')
            ->and($address->localPart())->toBe('asha.rai')
            ->and($address->domain())->toBe('wayne-foods.test')
            ->and($address->name)->toBe('Asha');
    });

    it('has no domain without an @', function (): void {
        $address = new EmailAddress('postmaster');

        expect($address->domain())->toBe('')->and($address->localPart())->toBe('postmaster');
    });
});

describe('ParsedAttachment', function (): void {
    it('measures its contents', function (): void {
        expect((new ParsedAttachment('a.txt', 'text/plain', 'abc'))->size())->toBe(3);
    });
});

describe('RawHeaders', function (): void {
    it('unfolds continuation lines and keeps repeated fields in order', function (): void {
        $headers = RawHeaders::parse("Received: one\r\n\tcontinued\r\nReceived: two\r\nSubject: Hi\r\n folded\r\nno colon line\r\n: empty name\r\n\r\nBody: not a header");

        expect($headers)->toBe([
            'received' => ['one continued', 'two'],
            'subject' => ['Hi folded'],
        ]);
    });

    it('reads a header block without a body', function (): void {
        expect(RawHeaders::parse("X-A: 1\n\nX-Ignored: 2"))->toBe(['x-a' => ['1']])
            ->and(RawHeaders::parse('X-Only: yes'))->toBe(['x-only' => ['yes']])
            ->and(RawHeaders::parse(" leading continuation\nX-B: 2"))->toBe(['x-b' => ['2']])
            ->and(RawHeaders::parse("\nX-C: 3"))->toBe(['x-c' => ['3']]);
    });
});

describe('AutomatedMailDetector', function (): void {
    it('classifies automated mail by its headers', function (array $headers, ?string $from, ?string $expected): void {
        $email = emailWith($headers, $from === null ? null : new EmailAddress($from));

        expect((new AutomatedMailDetector)->detect($email))->toBe($expected);
    })->with([
        'delivery status report' => [['content-type' => ['multipart/report; report-type=delivery-status; boundary=x']], null, 'bounce'],
        'X-Failed-Recipients' => [['x-failed-recipients' => ['gone@x.test']], null, 'bounce'],
        'null sender' => [['return-path' => ['<>']], null, 'bounce'],
        'empty return path' => [['return-path' => ['']], null, 'bounce'],
        'mailer-daemon' => [[], 'MAILER-DAEMON@mx.x.test', 'bounce'],
        'postmaster' => [[], 'postmaster@x.test', 'bounce'],
        'Auto-Submitted: auto-replied' => [['auto-submitted' => ['auto-replied']], null, 'auto_reply'],
        'Auto-Submitted: auto-generated' => [['auto-submitted' => ['Auto-Generated']], null, 'auto_reply'],
        'X-Autoreply' => [['x-autoreply' => ['yes']], null, 'auto_reply'],
        'X-Autorespond' => [['x-autorespond' => ['1']], null, 'auto_reply'],
        'X-Auto-Reply' => [['x-auto-reply' => ['1']], null, 'auto_reply'],
        'Precedence: bulk' => [['precedence' => ['bulk']], null, 'auto_reply'],
        'Precedence: auto_reply' => [['precedence' => [' Auto_Reply ']], null, 'auto_reply'],
        'a person (Auto-Submitted: no)' => [['auto-submitted' => ['no'], 'precedence' => ['normal'], 'return-path' => ['<a@x.test>']], null, null],
        'a person (no headers)' => [[], null, null],
        'a disposition report is not a bounce' => [['content-type' => ['multipart/report; report-type=disposition-notification']], null, null],
    ]);

    it('treats a message without a sender as a person unless a header says otherwise', function (): void {
        $email = new ParsedEmail(null, null, [], [], '', null, [], [], [], null, null);

        expect((new AutomatedMailDetector)->detect($email))->toBeNull();
    });
});

describe('InboundAddresses', function (): void {
    $ticket = '01a0c741-7f6f-7207-8157-9c81968767fa';

    it('reads our ticket, intake and message-id shapes on the mail domain only', function () use ($ticket): void {
        $addresses = new InboundAddresses('SHP.example');

        expect($addresses->ticketId("ticket+{$ticket}@shp.example"))->toBe($ticket)
            ->and($addresses->ticketId(' Ticket+'.strtoupper($ticket).'@SHP.EXAMPLE '))->toBe($ticket)
            ->and($addresses->ticketId("ticket+{$ticket}@evil.example"))->toBeNull()
            ->and($addresses->ticketId("ticket+{$ticket}@shp.example.evil"))->toBeNull()
            ->and($addresses->ticketId('ticket+not-a-uuid@shp.example'))->toBeNull()
            ->and($addresses->workspaceSlug('support+acme@shp.example'))->toBe('acme')
            ->and($addresses->workspaceSlug('support+globex-eu@shp.example'))->toBe('globex-eu')
            ->and($addresses->workspaceSlug('support+-bad@shp.example'))->toBeNull()
            ->and($addresses->workspaceSlug('support@shp.example'))->toBeNull()
            ->and($addresses->ticketIdFromMessageId("ticket-{$ticket}.3@shp.example"))->toBe($ticket)
            ->and($addresses->ticketIdFromMessageId("<ticket-{$ticket}.0@shp.example>"))->toBe($ticket)
            ->and($addresses->ticketIdFromMessageId("ticket-{$ticket}.3@other.example"))->toBeNull()
            ->and($addresses->ticketIdFromMessageId('CAF+gmail@mail.gmail.test'))->toBeNull();
    });

    it('collects candidates in routing order without duplicates', function () use ($ticket): void {
        $other = '01a0c741-0000-7207-8157-9c81968767fa';
        $email = emailWith(
            headers: ['delivered-to' => ["ticket+{$other}@shp.example"], 'x-original-to' => ['<support+globex@shp.example>, inbound@shp.example']],
            to: [new EmailAddress("ticket+{$ticket}@shp.example"), new EmailAddress('support+acme@shp.example')],
            inReplyTo: ["ticket-{$ticket}.2@shp.example"],
            references: ["ticket-{$other}.0@shp.example", "ticket-{$ticket}.1@shp.example", 'unrelated@x.test'],
            cc: [new EmailAddress("ticket+{$ticket}@shp.example")],
        );

        $candidates = (new InboundAddresses('shp.example'))->candidates($email);

        expect($candidates->plusAddressTickets)->toBe([$ticket, $other])
            ->and($candidates->threadTickets)->toBe([$ticket, $other])
            ->and($candidates->intakeWorkspaces)->toBe(['acme', 'globex'])
            ->and($candidates->isEmpty())->toBeFalse();
    });

    it('finds nothing in a message that names none of our addresses', function (): void {
        $candidates = (new InboundAddresses('shp.example'))->candidates(emailWith(to: [new EmailAddress('someone@else.test')]));

        expect($candidates)->toEqual(new RouteCandidates([], [], []))
            ->and($candidates->isEmpty())->toBeTrue();
    });
});

describe('SubjectLine', function (): void {
    it('turns a subject into a ticket title', function (string $subject, string $title): void {
        expect(SubjectLine::toTitle($subject))->toBe($title);
    })->with([
        'plain' => ['VPN drops every hour', 'VPN drops every hour'],
        'reply and tag' => ['Re: [#1042] Printer offline', 'Printer offline'],
        'stacked prefixes' => ['RE: Fwd: AW: SV: Re[2]: FW:  Invoice', 'Invoice'],
        'white space' => ["  Two\t\n lines  ", 'Two lines'],
        'empty' => ['', '(no subject)'],
        'only prefixes' => ['Re: Fwd:', '(no subject)'],
        'a word that starts like a prefix' => ['Refund request', 'Refund request'],
    ]);

    it('cuts long subjects at 200 characters', function (): void {
        expect(mb_strlen(SubjectLine::toTitle(str_repeat('é', 300))))->toBe(200);
    });
});

describe('ImapInboundMailbox::rawMessage', function (): void {
    it('keeps a body that already carries the headers and prepends them otherwise', function (): void {
        $header = "From: a@b.test\r\nSubject: hi";

        expect(ImapInboundMailbox::rawMessage($header."\r\n", $header."\r\n\r\nbody"))->toBe($header."\r\n\r\nbody")
            ->and(ImapInboundMailbox::rawMessage($header, 'body'))->toBe($header."\r\n\r\nbody")
            ->and(ImapInboundMailbox::rawMessage('', 'whole'))->toBe('whole');
    });
});
