<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Text\HtmlToText;
use App\Modules\Automation\Domain\Text\ReplyParser;

// HTML parts of email as plain text for ReplyParser (docs/04-domain/email.md §ReplyParser).

it('reads blocks and line breaks as lines and collapses white space', function (): void {
    expect((new HtmlToText)->convert("<div>Hello,</div><p>the   printer\n  works.</p>Line one<br>Line two"))
        ->toBe("Hello,\n\nthe printer works.\nLine one\nLine two");
});

it('drops scripts, styles and the head, and decodes entities as UTF-8', function (): void {
    expect((new HtmlToText)->convert('<html><head><title>T</title><style>p{}</style></head><body><script>x()</script><p>Caf&eacute; &amp; ü&nbsp;ok</p></body></html>'))
        ->toBe('Café & ü ok');
});

it('prefixes quoted blocks with "> " and turns a rule into the Outlook separator', function (): void {
    $text = (new HtmlToText)->convert('<p>New text</p><blockquote><p>old</p><p>older</p></blockquote><hr><p>From: x</p>');

    expect($text)->toBe("New text\n\n> old\n>\n> older\n\n__________\n\nFrom: x");
});

it('lets ReplyParser cut an HTML reply at the quote, the attribution or the rule', function (string $html, string $expected): void {
    expect((new ReplyParser)->parse((new HtmlToText)->convert($html)))->toBe($expected);
})->with([
    'Gmail' => ['<div dir="ltr">Works now.</div><div class="gmail_quote"><div class="gmail_attr">On Tue, 22 Sept 2026 at 09:40, Acme &lt;a@b.c&gt; wrote:<br></div><blockquote>old</blockquote></div>', 'Works now.'],
    'Outlook' => ['<div>Still broken.</div><hr><div id="divRplyFwdMsg"><b>From:</b> Acme</div>', 'Still broken.'],
    'blockquote only' => ['<p>Yes.</p><blockquote type="cite">Did it work?</blockquote>', 'Yes.'],
]);

it('returns an empty string for empty or markup-only input', function (): void {
    expect((new HtmlToText)->convert(''))->toBe('')
        ->and((new HtmlToText)->convert("  \n "))->toBe('')
        ->and((new HtmlToText)->convert('<div><!-- nothing --></div>'))->toBe('');
});
