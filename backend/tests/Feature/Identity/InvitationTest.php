<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Notifications\UserInvitation;
use App\Modules\Tenancy\Support\TenantResolver;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-18 09:00:00');
    $this->app->instance(Clock::class, $this->clock);

    $this->acme = createTenant('acme');
    $this->user = createTenantUser($this->acme, ['email' => 'asha@acme.test', 'password' => null, 'name' => 'Invited user', 'email_verified_at' => null]);
    $this->token = Invitation::newToken();

    tenancy()->initialize($this->acme);
    $this->invitation = Invitation::query()->create([
        'user_id' => $this->user->id,
        'token_hash' => Invitation::hashToken($this->token),
        'role_names' => ['agent'],
        'expires_at' => $this->clock->now()->addHours(48),
    ]);
    tenancy()->end();

    fromSpaOrigin();
});

function accept(string $token, array $payload = []): TestResponse
{
    return test()->postJson("/v1/auth/invitations/{$token}/accept", [
        'workspace' => 'acme',
        'password' => 'a-good-long-password',
        'password_confirmation' => 'a-good-long-password',
        ...$payload,
    ]);
}

it('sets the password, signs the user in and marks the invitation used', function (): void {
    accept($this->token, ['name' => 'Asha Sharma'])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'asha@acme.test')
        ->assertJsonPath('data.user.name', 'Asha Sharma')
        ->assertJsonPath('data.tenant.slug', 'acme');

    $user = $this->user->fresh();

    expect($user->password)->not->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at?->toIso8601String())->toBe('2026-09-18T09:00:00+00:00')
        ->and($this->invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(session(TenantResolver::SESSION_KEY))->toBe($this->acme->id)
        ->and(auth('web')->id())->toBe($this->user->id)
        ->and(AuditLog::query()->where('action', 'user.invitation_accepted')->count())->toBe(1);
});

it('lets the invited user sign in afterwards', function (): void {
    accept($this->token)->assertOk();
    $this->postJson('/v1/auth/logout')->assertNoContent();

    $this->postJson('/v1/auth/login', [
        'workspace' => 'acme',
        'email' => 'asha@acme.test',
        'password' => 'a-good-long-password',
    ])->assertOk();
});

it('accepts a token only once', function (): void {
    accept($this->token)->assertOk();

    accept($this->token)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
});

it('refuses an unknown or expired token', function (string $case): void {
    if ($case === 'expired') {
        $this->clock->advance('49 hours');
    }

    $token = $case === 'unknown' ? Invitation::newToken() : $this->token;

    accept($token)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
})->with(['unknown', 'expired']);

it('refuses a token belonging to another workspace', function (): void {
    createTenant('globex');

    accept($this->token, ['workspace' => 'globex'])->assertUnauthorized();
});

it('requires a confirmed strong password', function (array $payload): void {
    accept($this->token, $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'not confirmed' => [['password_confirmation' => 'something-else']],
]);

it('sends the invitation mail with a workspace link', function (): void {
    Notification::fake();

    $this->user->notify(new UserInvitation($this->token, 'acme', 'Acme'));

    Notification::assertSentTo($this->user, UserInvitation::class, function (UserInvitation $notification) {
        $mail = $notification->toMail($this->user);

        return str_contains($mail->actionUrl, "https://app.shp.localhost/acme/accept-invitation?token={$this->token}");
    });
});
