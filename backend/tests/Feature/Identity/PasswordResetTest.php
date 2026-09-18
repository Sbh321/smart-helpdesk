<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Notifications\PasswordResetLink;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-18 09:00:00');
    $this->app->instance(Clock::class, $this->clock);

    $this->acme = createTenant('acme');
    $this->user = createTenantUser($this->acme, ['email' => 'priya@acme.test', 'password' => Hash::make('old-password')]);
    fromSpaOrigin();
    Notification::fake();
});

function forgot(array $payload = []): TestResponse
{
    return test()->postJson('/v1/auth/password/forgot', ['workspace' => 'acme', 'email' => 'priya@acme.test', ...$payload]);
}

function capturedResetToken(): string
{
    $token = null;

    Notification::assertSentTo(test()->user, PasswordResetLink::class, function (PasswordResetLink $notification) use (&$token): bool {
        preg_match('/token=([^&]+)/', $notification->toMail(test()->user)->actionUrl, $matches);
        $token = $matches[1] ?? null;

        return true;
    });

    return (string) $token;
}

it('emails a reset link and stores a hashed token for the workspace', function (): void {
    forgot()->assertStatus(202)->assertJsonPath('data.status', 'sent');

    $row = DB::table('password_reset_tokens')->where('email', 'priya@acme.test')->sole();

    expect($row->tenant_id)->toBe($this->acme->id)
        ->and($row->token)->not->toBe(capturedResetToken())
        ->and(Hash::check(capturedResetToken(), $row->token))->toBeTrue();
});

it('answers the same for an unknown address and sends nothing', function (): void {
    forgot(['email' => 'nobody@acme.test'])->assertStatus(202);

    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('sends nothing for a disabled user', function (): void {
    $this->user->forceFill(['is_active' => false])->save();

    forgot()->assertStatus(202);

    Notification::assertNothingSent();
});

it('sets a new password, invalidates the token and records it', function (): void {
    forgot()->assertStatus(202);
    $token = capturedResetToken();

    $this->postJson('/v1/auth/password/reset', [
        'workspace' => 'acme',
        'email' => 'priya@acme.test',
        'token' => $token,
        'password' => 'a-good-long-password',
        'password_confirmation' => 'a-good-long-password',
    ])->assertOk()->assertJsonPath('data.status', 'reset');

    expect(Hash::check('a-good-long-password', (string) $this->user->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'user.password_reset')->count())->toBe(1);

    $this->postJson('/v1/auth/login', [
        'workspace' => 'acme',
        'email' => 'priya@acme.test',
        'password' => 'a-good-long-password',
    ])->assertOk();
});

it('refuses a wrong, reused or expired token', function (string $case): void {
    forgot();
    $token = capturedResetToken();

    if ($case === 'expired') {
        $this->clock->advance('61 minutes');
    }

    $payload = [
        'workspace' => 'acme',
        'email' => 'priya@acme.test',
        'token' => $case === 'wrong' ? 'not-the-token' : $token,
        'password' => 'a-good-long-password',
        'password_confirmation' => 'a-good-long-password',
    ];

    if ($case === 'reused') {
        $this->postJson('/v1/auth/password/reset', $payload)->assertOk();
    }

    $this->postJson('/v1/auth/password/reset', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_credentials');
})->with(['wrong', 'reused', 'expired']);

it('does not accept a token from another workspace', function (): void {
    createTenant('globex');
    forgot();
    $token = capturedResetToken();

    $this->postJson('/v1/auth/password/reset', [
        'workspace' => 'globex',
        'email' => 'priya@acme.test',
        'token' => $token,
        'password' => 'a-good-long-password',
        'password_confirmation' => 'a-good-long-password',
    ])->assertUnauthorized();
});
