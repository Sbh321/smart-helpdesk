<?php

declare(strict_types=1);

use App\Modules\Identity\Notifications\WorkspaceReminder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * The workspace finder (M5-02, docs/07-api/authentication.md §Workspace finder): one answer for every
 * address, one mail listing the active workspaces where the address has an active account.
 */
beforeEach(function (): void {
    $this->acme = createTenant('acme', ['name' => 'Acme Support']);
    $this->globex = createTenant('globex', ['name' => 'Globex']);
    createTenantUser($this->acme, ['email' => 'priya@acme.test']);
    fromSpaOrigin();
    Notification::fake();
});

function remind(string $email): TestResponse
{
    return test()->postJson('/v1/auth/workspace-reminder', ['email' => $email]);
}

/**
 * @return list<array{name: string, slug: string}> the workspaces mailed to the address (fails when nothing was)
 */
function remindedWorkspaces(string $email): array
{
    $found = [];

    Notification::assertSentOnDemand(WorkspaceReminder::class, function (WorkspaceReminder $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$found): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }
        $found = $notification->workspaces;

        return true;
    });

    return $found;
}

it('mails the workspace an address belongs to, with its sign-in link', function (): void {
    remind('priya@acme.test')->assertStatus(202)->assertExactJson(['data' => ['status' => 'sent']]);

    expect(remindedWorkspaces('priya@acme.test'))->toBe([['name' => 'Acme Support', 'slug' => 'acme']]);

    Notification::assertSentOnDemand(WorkspaceReminder::class, function (WorkspaceReminder $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        $mail = $notification->toMail($notifiable);

        return $channels === ['mail']
            && $mail->subject === 'Your Smart Helpdesk workspaces'
            && $mail->actionUrl === 'https://'.config('helpdesk.hosts.app').'/acme/login'
            && str_contains(implode("\n", $mail->introLines), 'Acme Support: https://'.config('helpdesk.hosts.app').'/acme/login');
    });
});

it('lists every workspace in one mail and matches the address without regard to case', function (): void {
    createTenantUser($this->globex, ['email' => 'Priya@Acme.test']);

    remind('PRIYA@acme.test')->assertStatus(202);

    Notification::assertSentOnDemandTimes(WorkspaceReminder::class, 1);
    Notification::assertSentOnDemand(WorkspaceReminder::class, fn (WorkspaceReminder $notification): bool => $notification->workspaces === [
        ['name' => 'Acme Support', 'slug' => 'acme'],
        ['name' => 'Globex', 'slug' => 'globex'],
    ]);
});

it('answers an unknown address exactly like a known one and sends nothing', function (): void {
    $known = remind('priya@acme.test');
    Notification::fake();
    $unknown = remind('nobody@example.test');

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toBe($known->json());
    Notification::assertNothingSent();
});

it('never lists a suspended workspace or a disabled account', function (): void {
    $this->acme->forceFill(['status' => 'suspended'])->save();
    createTenantUser($this->globex, ['email' => 'priya@acme.test', 'is_active' => false]);

    remind('priya@acme.test')->assertStatus(202);

    Notification::assertNothingSent();
});

it('leaves no workspace context behind', function (): void {
    createTenantUser($this->globex, ['email' => 'priya@acme.test']);

    remind('priya@acme.test')->assertStatus(202);

    expect(tenancy()->initialized)->toBeFalse();
});

it('rejects an address that is not one', function (): void {
    remind('not-an-address')->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    Notification::assertNothingSent();
});

it('allows three requests a minute for an address', function (): void {
    foreach (range(1, 3) as $attempt) {
        remind('priya@acme.test')->assertStatus(202);
    }

    remind('priya@acme.test')->assertStatus(429);
});
