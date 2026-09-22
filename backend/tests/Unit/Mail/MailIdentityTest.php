<?php

declare(strict_types=1);

use App\Modules\Mail\Support\MailIdentity;

// How a workspace appears in mail (docs/04-domain/email.md §Addresses and identities).

it('sends from the intake plus-address with "<Workspace> Support" by default', function (?string $setting): void {
    $identity = new MailIdentity('mail.test', 'acme', 'Acme', $setting);

    expect($identity->senderName())->toBe('Acme Support')
        ->and($identity->defaultSenderName())->toBe('Acme Support')
        ->and($identity->senderAddress())->toBe('support+acme@mail.test')
        ->and($identity->intakeAddress())->toBe('support+acme@mail.test')
        ->and($identity->replyToPattern())->toBe('ticket+<ticket-id>@mail.test');
})->with([null, '', '   ']);

it('uses the workspace setting as the display name', function (): void {
    $identity = new MailIdentity('mail.test', 'acme', 'Acme', '  Acme Customer Care ');

    expect($identity->senderName())->toBe('Acme Customer Care')
        ->and($identity->senderAddress())->toBe('support+acme@mail.test');
});

it('does not repeat "Support" when the workspace name already ends with it', function (string $workspace, string $expected): void {
    expect((new MailIdentity('mail.test', 'acme', $workspace, null))->senderName())->toBe($expected);
})->with([
    ['Acme Support', 'Acme Support'],
    ['Globex IT support', 'Globex IT support'],
    ['Supportive Co', 'Supportive Co Support'],
]);
