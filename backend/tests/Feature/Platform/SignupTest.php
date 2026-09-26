<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Platform\Models\WorkspaceSignup;
use App\Modules\Platform\Notifications\VerifySignup;
use App\Modules\Platform\Notifications\WorkspaceWelcome;
use App\Modules\Platform\Support\SignupSettings;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * Self sign-up (ADR-0025 §8, roadmap M6-05): a request and an email link, then a workspace on the free
 * trial with its owner signed up; nothing exists before the link is followed.
 */

beforeEach(function (): void {
    Notification::fake();
    app(SyncPermissionCatalogue::class)();
    $this->clock = new FrozenClock('2026-10-01 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
});

/** @return array<string, mixed> */
function signupBody(array $overrides = []): array
{
    return [
        'name' => 'Asha Gurung', 'email' => 'asha@himal.test', 'password' => 'correct-horse-battery-9',
        'password_confirmation' => 'correct-horse-battery-9', 'workspace_name' => 'Himal Support', 'slug' => 'himal',
        'timezone' => 'Asia/Kathmandu', ...$overrides,
    ];
}

/** Signs up and returns the token from the verification email. */
function signUp(array $overrides = []): string
{
    test()->postJson('/v1/signup', signupBody($overrides))->assertAccepted();
    $token = '';
    Notification::assertSentTo(new AnonymousNotifiable, VerifySignup::class, function (VerifySignup $mail, array $channels, AnonymousNotifiable $to) use (&$token, $overrides): bool {
        if (($to->routes['mail'] ?? null) !== ($overrides['email'] ?? 'asha@himal.test')) {
            return false;
        }
        preg_match('/token=([A-Za-z0-9]{48})/', (string) $mail->toMail($to)->actionUrl, $match);
        $token = $match[1] ?? '';

        return true;
    });

    return $token;
}

it('creates nothing until the link is followed, then a workspace on the trial with its owner', function (): void {
    $token = signUp();
    expect(Tenant::findBySlug('himal'))->toBeNull();

    $this->postJson('/v1/signup/verify', ['token' => $token])->assertCreated()
        ->assertJsonPath('data.slug', 'himal')
        ->assertJsonPath('data.support_email', 'support+himal@'.config('helpdesk.hosts.mail'));

    // The owner is told where to sign in and which address customers write to.
    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceWelcome::class, function (WorkspaceWelcome $mail, array $channels, AnonymousNotifiable $to): bool {
        $message = $mail->toMail($to);
        $text = implode("\n", $message->introLines);

        return ($to->routes['mail'] ?? null) === 'asha@himal.test'
            && str_contains($text, 'support+himal@'.config('helpdesk.hosts.mail'))
            && str_contains($text, '14-day free trial')
            && $message->actionUrl === 'https://'.config('helpdesk.hosts.app').'/himal/login';
    });

    $tenant = Tenant::findBySlug('himal');
    expect($tenant?->name)->toBe('Himal Support')
        ->and($tenant?->timezone)->toBe('Asia/Kathmandu')
        ->and(Subscription::forTenant((string) $tenant?->id)?->plan->code)->toBe('trial');
    $owner = $tenant?->run(fn () => User::query()->where('email', 'asha@himal.test')->sole());
    expect($owner?->is_active)->toBeTrue()
        ->and($tenant?->run(fn () => $owner?->hasRole('owner')))->toBeTrue();

    // The owner signs in with the password chosen at sign-up.
    fromSpaOrigin();
    $this->postJson('/v1/auth/login', ['workspace' => 'himal', 'email' => 'asha@himal.test', 'password' => 'correct-horse-battery-9'])->assertOk();

    // The link works once.
    $this->postJson('/v1/signup/verify', ['token' => $token])->assertUnprocessable()->assertJsonPath('code', 'link_expired');
});

it('checks the address live: free, taken, reserved, malformed', function (): void {
    createTenant('acme');

    $read = fn (string $slug): array => $this->getJson('/v1/signup/address?slug='.$slug)->assertOk()->json('data');
    expect($read('himal'))->toBe(['slug' => 'himal', 'available' => true, 'reason' => null])
        ->and($read('acme')['reason'])->toBe('taken')
        ->and($read('admin')['reason'])->toBe('reserved')
        ->and($read('Not Valid!')['reason'])->toBe('invalid');
});

it('holds an address for a waiting sign-up, but not against the same person', function (): void {
    signUp();

    $this->postJson('/v1/signup', signupBody(['email' => 'someone@else.test']))->assertUnprocessable()->assertJsonValidationErrors(['slug']);
    // The same person may ask again; the earlier link stops working.
    $first = WorkspaceSignup::query()->sole()->id;
    $this->postJson('/v1/signup', signupBody())->assertAccepted();
    expect(WorkspaceSignup::query()->pluck('id')->all())->not->toContain($first);
});

it('lets a link expire after a day', function (): void {
    $token = signUp();
    $this->clock->set('2026-10-02 09:00:01');

    $this->postJson('/v1/signup/verify', ['token' => $token])->assertUnprocessable()->assertJsonPath('code', 'link_expired');
    expect(Tenant::findBySlug('himal'))->toBeNull();
});

it('refuses when the address was taken before the link was followed', function (): void {
    $token = signUp();
    createTenant('himal');

    $this->postJson('/v1/signup/verify', ['token' => $token])->assertUnprocessable()->assertJsonPath('code', 'link_expired');
});

it('drops a request with the honeypot filled, and refuses everything while sign-up is closed', function (): void {
    $this->postJson('/v1/signup', signupBody(['website' => 'https://spam.test']))->assertAccepted();
    Notification::assertNothingSent();
    expect(WorkspaceSignup::query()->count())->toBe(0);

    app(SignupSettings::class)->setEnabled(false);
    $this->postJson('/v1/signup', signupBody())->assertForbidden()->assertJsonPath('code', 'signup_closed');
});

it('validates the form', function (array $overrides, string $field): void {
    $this->postJson('/v1/signup', signupBody($overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'weak password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'no confirmation' => [['password_confirmation' => 'something-else-1'], 'password'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'reserved address' => [['slug' => 'api'], 'slug'],
    'no workspace name' => [['workspace_name' => ''], 'workspace_name'],
    // Chromium browsers report some zones by their old names; the API takes only the current ones.
    'old zone name' => [['timezone' => 'Asia/Katmandu'], 'timezone'],
]);

it('limits how often one address can sign up', function (): void {
    foreach (range(1, 3) as $n) {
        $this->postJson('/v1/signup', signupBody(['slug' => "himal-{$n}"]))->assertAccepted();
    }
    $this->postJson('/v1/signup', signupBody(['slug' => 'himal-4']))->assertTooManyRequests();
});

it('lets an operator turn the limits off with 0 (local development and demos)', function (): void {
    config(['helpdesk.platform.signup_limits.per_ip_hour' => 0, 'helpdesk.platform.signup_limits.per_email_hour' => 0]);
    foreach (range(1, 6) as $n) {
        $this->postJson('/v1/signup', signupBody(['slug' => "himal-{$n}"]))->assertAccepted();
    }
});
