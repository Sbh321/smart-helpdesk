<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Platform\Models\PlatformInvitation;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Notifications\PlatformAdminInvitation;
use App\Modules\Platform\Notifications\PlatformPasswordReset;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Platform admin accounts (ADR-0025 §7, roadmap M6-04): invitations, forgotten passwords, one's own
 * name and password, and deactivation that ends a session on the next request.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->clock = new FrozenClock('2026-10-01 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
});

const STRONG = 'correct-horse-battery-9';

/** Invites an admin as the signed-in admin and returns the invitation token from the email. */
function inviteAdmin(string $email = 'rita@platform.test'): string
{
    test()->postJson(onPlatform('admins'), ['name' => 'Rita', 'email' => $email])->assertCreated()
        ->assertJsonPath('data.status', 'invited');
    $token = '';
    Notification::assertSentTo(
        PlatformUser::query()->where('email', $email)->sole(),
        PlatformAdminInvitation::class,
        function (PlatformAdminInvitation $mail) use (&$token): bool {
            preg_match('/token=([A-Za-z0-9]{48})/', (string) $mail->toMail(new stdClass)->actionUrl, $match);
            $token = $match[1] ?? '';

            return $token !== '';
        },
    );

    return $token;
}

it('invites an admin who accepts, sets a password and is signed in', function (): void {
    actingAsPlatformAdmin();
    $token = inviteAdmin();

    $this->getJson(onPlatform("auth/invitations/{$token}"))->assertOk()->assertJsonPath('data.email', 'rita@platform.test');
    $this->postJson(onPlatform('auth/login'), ['email' => 'rita@platform.test', 'password' => STRONG])->assertUnauthorized();

    $this->postJson(onPlatform("auth/invitations/{$token}/accept"), ['name' => 'Rita Shah', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $this->postJson(onPlatform("auth/invitations/{$token}/accept"), ['name' => 'Rita Shah', 'password' => STRONG, 'password_confirmation' => STRONG])
        ->assertOk()->assertJsonPath('data.name', 'Rita Shah');
    $this->getJson(onPlatform('me'))->assertOk()->assertJsonPath('data.email', 'rita@platform.test');

    // Single use.
    $this->getJson(onPlatform("auth/invitations/{$token}"))->assertUnprocessable()->assertJsonPath('code', 'link_expired');
    expect(AuditLog::query()->where('action', 'platform_user.invitation_accepted')->count())->toBe(1);
});

it('lets an invitation expire after 48 hours, and a resend replaces the link', function (): void {
    actingAsPlatformAdmin();
    $first = inviteAdmin();
    $rita = PlatformUser::query()->where('email', 'rita@platform.test')->sole();

    $this->postJson(onPlatform("admins/{$rita->id}/resend-invitation"))->assertOk();
    $this->getJson(onPlatform("auth/invitations/{$first}"))->assertUnprocessable();
    expect(PlatformInvitation::query()->where('platform_user_id', $rita->id)->count())->toBe(1);

    $this->clock->set('2026-10-03 09:00:01');
    $current = PlatformInvitation::query()->where('platform_user_id', $rita->id)->sole();
    expect(PlatformInvitation::findUsable('x', $this->clock->now()))->toBeNull()
        ->and($current->expires_at->lessThan($this->clock->now()))->toBeTrue();
});

it('refuses a second invitation to the same address and withdraws an open one', function (): void {
    actingAsPlatformAdmin();
    inviteAdmin();
    $this->postJson(onPlatform('admins'), ['name' => 'Rita', 'email' => 'RITA@platform.test'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);

    $rita = PlatformUser::query()->where('email', 'rita@platform.test')->sole();
    $this->deleteJson(onPlatform("admins/{$rita->id}/invitation"))->assertNoContent();
    expect(PlatformUser::query()->where('email', 'rita@platform.test')->exists())->toBeFalse();
});

it('sends a reset link only to an admin who can sign in, and answers the same either way', function (): void {
    $admin = PlatformUser::query()->create(['name' => 'Sam', 'email' => 'sam@platform.test', 'password' => STRONG]);

    $known = $this->postJson(onPlatform('auth/forgot-password'), ['email' => 'SAM@platform.test'])->assertAccepted()->json();
    $unknown = $this->postJson(onPlatform('auth/forgot-password'), ['email' => 'nobody@platform.test'])->assertAccepted()->json();
    expect($known)->toBe($unknown);

    $token = '';
    Notification::assertSentTo($admin, PlatformPasswordReset::class, function (PlatformPasswordReset $mail) use (&$token): bool {
        parse_str((string) parse_url((string) $mail->toMail(new stdClass)->actionUrl, PHP_URL_QUERY), $query);
        $token = (string) ($query['token'] ?? '');

        return str_contains((string) $mail->toMail(new stdClass)->actionUrl, '/platform/reset-password?');
    });

    $this->postJson(onPlatform('auth/reset-password'), ['token' => $token, 'email' => 'sam@platform.test', 'password' => 'new-horse-battery-7', 'password_confirmation' => 'new-horse-battery-7'])
        ->assertNoContent();
    expect(Hash::check('new-horse-battery-7', (string) $admin->fresh()?->password))->toBeTrue();
    $this->postJson(onPlatform('auth/reset-password'), ['token' => $token, 'email' => 'sam@platform.test', 'password' => 'other-horse-battery-7', 'password_confirmation' => 'other-horse-battery-7'])
        ->assertUnprocessable()->assertJsonPath('code', 'link_expired');
});

it('changes your own name and password, checking the current one', function (): void {
    $admin = actingAsPlatformAdmin();

    $this->patchJson(onPlatform('me'), ['name' => 'Platform owner'])->assertOk()->assertJsonPath('data.name', 'Platform owner');
    $this->putJson(onPlatform('me/password'), ['current_password' => 'wrong', 'password' => STRONG, 'password_confirmation' => STRONG])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
    $this->putJson(onPlatform('me/password'), ['current_password' => 'platform-password', 'password' => STRONG, 'password_confirmation' => STRONG])
        ->assertNoContent();
    expect(Hash::check(STRONG, (string) $admin->fresh()?->password))->toBeTrue();
});

it('deactivates an admin, who is signed out and cannot sign in, but never oneself', function (): void {
    $me = actingAsPlatformAdmin();
    $other = PlatformUser::query()->create(['name' => 'Sam', 'email' => 'sam@platform.test', 'password' => STRONG]);

    // Acting admins are always active, so refusing oneself also keeps the last active admin.
    $this->postJson(onPlatform("admins/{$me->id}/deactivate"))->assertForbidden();
    $this->postJson(onPlatform("admins/{$other->id}/deactivate"))->assertOk()->assertJsonPath('data.status', 'deactivated');
    expect(AuditLog::query()->where('action', 'platform_user.deactivated')->count())->toBe(1);

    $this->postJson(onPlatform('auth/login'), ['email' => 'sam@platform.test', 'password' => STRONG])->assertUnauthorized();
    // Sam's open session ends on the next request.
    $this->actingAs($other->fresh() ?? $other, 'platform');
    $this->getJson(onPlatform('me'))->assertUnauthorized();

    $this->actingAs($me, 'platform');
    $this->postJson(onPlatform("admins/{$other->id}/reactivate"))->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson(onPlatform('auth/login'), ['email' => 'sam@platform.test', 'password' => STRONG])->assertOk();
});

it('lists admins with their status', function (): void {
    actingAsPlatformAdmin();
    inviteAdmin();

    $statuses = collect($this->getJson(onPlatform('admins'))->assertOk()->json('data'))->pluck('status', 'email')->all();
    expect($statuses)->toBe(['admin@platform.test' => 'active', 'rita@platform.test' => 'invited']);
});
