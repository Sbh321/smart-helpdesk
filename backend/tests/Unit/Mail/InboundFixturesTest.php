<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Text\HtmlToText;
use App\Modules\Automation\Domain\Text\ReplyParser;
use App\Modules\Mail\Domain\AutomatedMailDetector;
use App\Modules\Mail\Domain\ParsedEmail;
use App\Modules\Mail\Support\MimeParser;

// The inbound fixture corpus (tests/Fixtures/inbound, docs/04-domain/email.md §Tests): each client's
// message through the MIME parser, ReplyParser (via HtmlToText when there is no text part) and the
// automated-mail detector.

const FIXTURE_TICKET = '01a0c741-7f6f-7207-8157-9c81968767fa';

function inboundFixture(string $name, string $ticketId = FIXTURE_TICKET): string
{
    return str_replace('{{TICKET}}', $ticketId, (string) file_get_contents(__DIR__."/../../Fixtures/inbound/{$name}.eml"));
}

function parsedFixture(string $name): ParsedEmail
{
    return (new MimeParser)->parse(inboundFixture($name));
}

function replyOf(ParsedEmail $email): string
{
    return (new ReplyParser)->parse($email->text ?? (new HtmlToText)->convert((string) $email->html));
}

it('keeps only the new text of each client\'s reply', function (string $fixture, string $expected): void {
    $email = parsedFixture($fixture);

    expect(replyOf($email))->toBe($expected)
        ->and((new AutomatedMailDetector)->detect($email))->toBeNull();
})->with([
    'Gmail' => ['gmail-reply', "Thanks, the printer works again.\n\nAsha"],
    'Outlook' => ['outlook-reply', "Hello,\n\nThe printer on floor 2 still shows ERR-17.\n\nRegards,\nBikram"],
    'Apple Mail' => ['apple-mail-reply', 'Thanks, I can print again. Café is open too.'],
    'plain text with a signature' => ['plain-new-ticket', "Hello,\n\nSince this morning the VPN drops every hour and I have to sign in again."],
    'HTML only' => ['html-only', "Could you send me a copy of invoice INV-204?\n\nThanks & regards,\nRavi"],
    'with attachments' => ['with-attachments', 'Here is the error screen and the printer log.'],
]);

it('cuts the HTML part of a multipart reply the same way as its text part', function (string $fixture): void {
    $email = parsedFixture($fixture);

    expect((new ReplyParser)->parse((new HtmlToText)->convert((string) $email->html)))->toBe((new ReplyParser)->parse((string) $email->text));
})->with(['gmail-reply', 'outlook-reply']);

it('recognises auto-replies and bounces', function (string $fixture, string $kind): void {
    expect((new AutomatedMailDetector)->detect(parsedFixture($fixture)))->toBe($kind);
})->with([
    'out-of-office' => ['auto-reply', AutomatedMailDetector::AUTO_REPLY],
    'delivery status notification' => ['bounce', AutomatedMailDetector::BOUNCE],
]);

it('decodes addresses, thread ids, the date and the subject', function (): void {
    $email = parsedFixture('gmail-reply');

    expect($email->messageId)->toBe('CAF+gmail-4411@mail.gmail.test')
        ->and($email->from?->address)->toBe('asha@wayne-foods.test')
        ->and($email->from?->name)->toBe('Asha Rai')
        ->and($email->to[0]->address)->toBe('ticket+'.FIXTURE_TICKET.'@shp.example')
        ->and($email->inReplyTo)->toBe(['ticket-'.FIXTURE_TICKET.'.1@shp.example'])
        ->and($email->references)->toBe(['ticket-'.FIXTURE_TICKET.'.0@shp.example', 'ticket-'.FIXTURE_TICKET.'.1@shp.example'])
        ->and($email->sentAt?->format(DATE_ATOM))->toBe('2026-09-22T09:41:12+05:45')
        ->and($email->subject)->toBe('Re: [#1042] Printer offline')
        ->and($email->recipients())->toBe(['ticket+'.FIXTURE_TICKET.'@shp.example', 'inbound@shp.example'])
        ->and($email->firstHeader('Return-Path'))->toBe('<asha@wayne-foods.test>')
        ->and($email->header('x-missing'))->toBe([]);
});

it('lists attachments with their declared type and marks inline parts', function (): void {
    $files = array_map(
        fn ($attachment): array => [$attachment->filename, $attachment->mime, $attachment->inline, $attachment->size() > 0],
        parsedFixture('with-attachments')->attachments,
    );

    expect($files)->toBe([
        ['screen.png', 'image/png', false, true],
        ['printer-log.pdf', 'application/pdf', false, true],
        ['fix.exe', 'application/x-msdownload', false, true],
        ['logo.png', 'image/png', true, true],
    ]);
});

it('parses a message without a Message-ID, a date or a sender', function (): void {
    $email = (new MimeParser)->parse("To: support+acme@shp.example\nSubject: hi\n\nbody\n");

    expect($email->messageId)->toBeNull()
        ->and($email->from)->toBeNull()
        ->and($email->sentAt)->toBeNull()
        ->and(trim((string) $email->text))->toBe('body');
});

it('ignores an unreadable date', function (): void {
    expect((new MimeParser)->parse("From: a@b.test\nDate: not a date\nSubject: x\n\nbody\n")->sentAt)->toBeNull();
});

it('reads a message whose added headers end in CRLF and whose own lines end in LF', function (): void {
    $raw = "Delivered-To: inbound@shp.example\r\nReceived: from x\r\nFrom: Zara <zara@x.test>\nTo: support+acme@shp.example\nSubject: Mixed\n\nHello\n";

    $email = (new MimeParser)->parse($raw);

    expect($email->from?->address)->toBe('zara@x.test')
        ->and($email->subject)->toBe('Mixed')
        ->and(trim((string) $email->text))->toBe('Hello');
});
