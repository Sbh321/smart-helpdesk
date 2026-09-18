<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Text\ReplyParser;

function replyFixture(string $name): string
{
    return (string) file_get_contents(__DIR__."/Fixtures/replies/{$name}.txt");
}

it('keeps only the new text of client replies', function (string $fixture, string $expected): void {
    expect((new ReplyParser)->parse(replyFixture($fixture)))->toBe($expected);
})->with([
    'Gmail (CRLF, wrapped attribution)' => ['gmail', "Hi team,\n\nThe export works now, thank you.\n\nAsha"],
    'Outlook (underscore separator and header block)' => ['outlook', "Hello,\n\nThe printer on floor 2 still shows ERR-17.\nCan someone look at it today?\n\nRegards,\nBikram"],
    'Outlook plain text' => ['outlook-plain', 'Yes, restarting fixed it.'],
    'Apple Mail (signature before the quote)' => ['apple-mail', 'Thanks, I can log in again.'],
    'From: header block after a blank line' => ['header-block', "Forwarding the details you asked for.\nFrom: this line is part of the reply and stays."],
]);

it('cuts at each kind of marker', function (string $body, string $expected): void {
    expect((new ReplyParser)->parse($body))->toBe($expected);
})->with([
    'quoted line' => ["Fixed.\n> old text", 'Fixed.'],
    'indented quote' => ["Fixed.\n   > old text", 'Fixed.'],
    'single-line attribution' => ["Fixed.\nOn Monday, Asha wrote:\nold", 'Fixed.'],
    'signature with trailing space' => ["Fixed.\n-- \nAsha", 'Fixed.'],
    'signature without trailing space' => ["Fixed.\r--\rAsha", 'Fixed.'],
    'original message, any case' => ["Fixed.\n----- original message -----\nold", 'Fixed.'],
]);

it('keeps text that only looks like a marker', function (string $body): void {
    expect((new ReplyParser)->parse($body))->toBe(trim($body));
})->with([
    'On without wrote' => "On Monday the VPN failed.\nIt still fails.",
    'attribution interrupted by a blank line' => "On Monday it failed\n\nsomebody wrote: nothing",
    'attribution wrapped over too many lines' => "On Monday\nthe\nprinter\nwrote:",
    'From: without following headers' => "Hello\n\nFrom: the logs I see an error.\nNothing else.",
    'double dash inside text' => 'Use the --force flag -- it helps.',
    'short underscore line' => "Fixed\n_____",
    'plain text' => "  Just a reply.  \n",
]);

it('keeps a bottom-posted reply without the quoted lines', function (): void {
    expect((new ReplyParser)->parse("> Can you check the printer?\n> Thanks\n\nIt works now."))->toBe('It works now.');
});

it('returns an empty string for an empty body', function (): void {
    expect((new ReplyParser)->parse(''))->toBe('')
        ->and((new ReplyParser)->parse("\n> only a quote\n"))->toBe('');
});
