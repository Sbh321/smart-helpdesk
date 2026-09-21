<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Support\LoginThrottle;
use App\Modules\Tenancy\Support\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = createTenantUser($this->acme, [
        'email' => 'priya@acme.test',
        'password' => Hash::make('correct-horse'),
    ]);
    // The test body reads and edits acme's rows, which row-level security shows only inside acme;
    // every request still starts in the central context (Tests\TestCase::call()).
    tenancy()->initialize($this->acme);
    fromSpaOrigin();
});

function login(array $overrides = []): TestResponse
{
    return test()->postJson('/v1/auth/login', [
        'workspace' => 'acme',
        'email' => 'priya@acme.test',
        'password' => 'correct-horse',
        ...$overrides,
    ]);
}

it('signs a user in and stores the workspace in the session', function (): void {
    login()
        ->assertOk()
        ->assertJsonPath('data.user.email', 'priya@acme.test')
        ->assertJsonPath('data.tenant.slug', 'acme')
        ->assertJsonPath('data.permissions', [])
        ->assertJsonPath('data.unread_notifications', 0)
        ->assertJsonMissingPath('data.user.password');

    expect(session(TenantResolver::SESSION_KEY))->toBe($this->acme->id)
        ->and(auth('web')->id())->toBe($this->user->id)
        ->and($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('records an audit entry for a successful sign-in', function (): void {
    login()->assertOk();

    $entry = AuditLog::query()->where('action', 'user.logged_in')->sole();

    expect($entry->tenant_id)->toBe($this->acme->id)
        ->and($entry->subject_id)->toBe($this->user->id);
});

it('answers the same way for a wrong password, unknown email and unknown workspace', function (array $payload): void {
    login($payload)
        ->assertUnauthorized()
        ->assertJson(['code' => 'invalid_credentials', 'detail' => 'The workspace, email or password is incorrect.']);

    expect(auth('web')->check())->toBeFalse();
})->with([
    'wrong password' => [['password' => 'wrong']],
    'unknown email' => [['email' => 'nobody@acme.test']],
    'unknown workspace' => [['workspace' => 'initech']],
    'right credentials, other workspace' => [['workspace' => 'globex']],
]);

it('refuses a disabled user', function (): void {
    $this->user->forceFill(['is_active' => false])->save();

    login()->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
});

it('refuses a user who has not accepted the invitation yet', function (): void {
    $this->user->forceFill(['password' => null])->save();

    login()->assertUnauthorized();
});

it('answers 403 for a suspended workspace', function (): void {
    $this->acme->update(['status' => 'suspended']);

    login()->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
});

it('validates the payload', function (): void {
    $this->postJson('/v1/auth/login', ['workspace' => 'ACME'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['workspace', 'email', 'password']]);
});

it('throttles to five attempts a minute per workspace, address and client', function (): void {
    foreach (range(1, 5) as $ignored) {
        login(['password' => 'wrong'])->assertUnauthorized();
    }

    login(['password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');
});

it('locks the account after ten failures and then refuses even the right password', function (): void {
    foreach (range(1, LoginThrottle::MAX_FAILURES) as $attempt) {
        // A different client address each time, so the per-minute throttle does not fire first.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt]);
        login(['password' => 'wrong'])->assertUnauthorized();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200']);
    login()
        ->assertForbidden()
        ->assertJson(['code' => 'account_locked', 'meta' => ['retry_after_minutes' => LoginThrottle::LOCK_MINUTES]]);
});

it('clears the failure counter after a successful sign-in', function (): void {
    login(['password' => 'wrong'])->assertUnauthorized();
    login()->assertOk();

    foreach (range(1, 3) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt]);
        login(['password' => 'wrong'])->assertUnauthorized();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99']);
    login()->assertOk();
});

it('signs out, destroys the session and records it', function (): void {
    login()->assertOk();

    $this->postJson('/v1/auth/logout')->assertNoContent();

    expect(auth('web')->check())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.logged_out')->count())->toBe(1);

    $this->getJson('/v1/me')->assertUnauthorized();
});

it('answers a browser request without a session with problem details, not a redirect', function (): void {
    $this->get('/v1/me')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated');
});

it('serves the CSRF cookie route the SPA calls first', function (): void {
    $this->get('/sanctum/csrf-cookie')->assertNoContent();
});

describe('/v1/me', function (): void {
    it('returns the user, workspace and permissions', function (): void {
        actingAsTenantUser($this->acme, $this->user);

        $this->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.tenant.timezone', 'Asia/Kathmandu');
    });

    it('is not reachable without a session', function (): void {
        $this->getJson('/v1/me')->assertUnauthorized();
    });

    it('updates interface preferences', function (): void {
        actingAsTenantUser($this->acme, $this->user);

        $this->patchJson('/v1/me/preferences', ['theme' => 'dark', 'density' => 'compact'])
            ->assertOk()
            ->assertJsonPath('data.user.preferences.theme', 'dark');

        expect($this->user->fresh()->preferences)->toBe(['theme' => 'dark', 'density' => 'compact']);
    });

    it('rejects an unknown theme', function (): void {
        actingAsTenantUser($this->acme, $this->user);

        $this->patchJson('/v1/me/preferences', ['theme' => 'neon'])->assertStatus(422);
    });
});

it('writes the tenant and guard onto the session row', function (): void {
    config(['session.driver' => 'database']);

    login()->assertOk();

    $row = DB::table('sessions')->latest('last_activity')->first();

    expect($row?->tenant_id)->toBe($this->acme->id)
        ->and($row?->user_id)->toBe($this->user->id)
        ->and($row?->guard)->toBe('web');
});

it('keeps the sign-in inside the workspace even when another workspace has the same email', function (): void {
    createTenantUser($this->globex, ['email' => 'priya@acme.test', 'password' => Hash::make('other-password')]);

    login(['password' => 'other-password'])->assertUnauthorized();
    login()->assertOk()->assertJsonPath('data.tenant.slug', 'acme');

    $count = fn (): int => User::query()->where('email', 'priya@acme.test')->count();

    expect($this->acme->run($count) + $this->globex->run($count))->toBe(2);
});
