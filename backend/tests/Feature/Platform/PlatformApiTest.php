<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;

/*
 * Tenant routes are requested with an explicit host here: Laravel's URL generator keeps the host of
 * the previous request, so a relative path after a platform call would resolve to the admin host.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->acme = createTenant('acme');
});

it('signs a platform admin in and out with its own guard', function (): void {
    PlatformUser::query()->create(['name' => 'Platform admin', 'email' => 'admin@platform.test', 'password' => 'platform-password']);

    $this->postJson(onPlatform('auth/login'), ['email' => 'admin@platform.test', 'password' => 'platform-password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'admin@platform.test');

    expect(auth('platform')->check())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform_user.logged_in')->sole()->tenant_id)->toBeNull();

    $this->getJson(onPlatform('me'))->assertOk();
    $this->postJson(onPlatform('auth/logout'))->assertNoContent();
    $this->getJson(onPlatform('me'))->assertUnauthorized();
});

// Regression (2026-09-23): the priority sorter moved StartSession ahead of UsePlatformSession, so the
// platform session used the tenant cookie name, and a guest 401 set the domain-wide tenant cookie; a
// browser then held two `shp_session` cookies and a successful sign-in never stuck.
it('keeps the platform session in its own host-only cookie', function (string $path, int $status): void {
    // Read before the request: the platform middleware rewrites `session.cookie` in this process.
    $tenantCookie = (string) config('session.cookie');
    $platformCookie = (string) config('helpdesk.platform.session_cookie');

    $response = $this->getJson(onPlatform($path))->assertStatus($status);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());

    expect($cookies)->toHaveKey($platformCookie)
        ->and($cookies)->not->toHaveKey($tenantCookie)
        ->and($cookies[$platformCookie]->getDomain())->toBeNull();
})->with([
    'csrf cookie' => ['csrf-cookie', 204],
    'guest /me' => ['me', 401],
]);

it('refuses wrong platform credentials', function (): void {
    PlatformUser::query()->create(['name' => 'Platform admin', 'email' => 'admin@platform.test', 'password' => 'platform-password']);

    $this->postJson(onPlatform('auth/login'), ['email' => 'admin@platform.test', 'password' => 'nope'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_credentials');
});

it('refuses platform routes to guests and to tenant users', function (): void {
    $this->getJson(onPlatform('tenants'))->assertUnauthorized();

    actingAsTenantUser($this->acme);
    $this->getJson(onPlatform('tenants'))->assertUnauthorized();
});

it('never lets a platform admin into tenant routes', function (): void {
    actingAsPlatformAdmin();

    $this->getJson(onApiHost('/v1/me'))->assertUnauthorized();
});

it('serves the platform API only on the admin host', function (): void {
    actingAsPlatformAdmin();

    $this->getJson('https://'.config('helpdesk.hosts.api').'/platform-api/tenants')->assertNotFound();
});

it('lists, filters and shows workspaces', function (): void {
    actingAsPlatformAdmin();
    createTenant('globex');

    $this->getJson(onPlatform('tenants'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'status', 'owner_email', 'timezone']], 'meta' => ['total']]);

    $this->getJson(onPlatform('tenants?search=glob'))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(onPlatform("tenants/{$this->acme->id}"))->assertOk()->assertJsonPath('data.slug', 'acme');
});

it('provisions a workspace over the API', function (): void {
    actingAsPlatformAdmin();

    $this->postJson(onPlatform('tenants'), [
        'slug' => 'initech',
        'name' => 'Initech',
        'owner_email' => 'owner@initech.test',
        'timezone' => 'Europe/London',
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'initech')
        ->assertJsonPath('data.timezone', 'Europe/London');

    expect(Tenant::findBySlug('initech'))->not->toBeNull();
});

it('validates the workspace payload', function (array $payload, string $field): void {
    actingAsPlatformAdmin();

    $this->postJson(onPlatform('tenants'), [
        'slug' => 'initech',
        'name' => 'Initech',
        'owner_email' => 'owner@initech.test',
        ...$payload,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'reserved slug' => [['slug' => 'admin'], 'slug'],
    'malformed slug' => [['slug' => 'Not Valid'], 'slug'],
    'duplicate slug' => [['slug' => 'acme'], 'slug'],
    'bad email' => [['owner_email' => 'not-an-email'], 'owner_email'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
]);

it('updates workspace details and records the change', function (): void {
    actingAsPlatformAdmin();

    $this->patchJson(onPlatform("tenants/{$this->acme->id}"), ['name' => 'Acme Limited'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Limited')
        ->assertJsonPath('data.subscription.state', 'none');

    expect(AuditLog::query()->where('action', 'tenant.updated')->count())->toBe(1);
});

it('suspends and reactivates a workspace, locking its users out in between', function (): void {
    $user = createTenantUser($this->acme);
    actingAsPlatformAdmin();

    $this->postJson(onPlatform("tenants/{$this->acme->id}/suspend"), ['reason' => 'unpaid'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    actingAsTenantUser($this->acme, $user);
    $this->getJson(onApiHost('/v1/me'))->assertForbidden()->assertJsonPath('code', 'tenant_suspended');

    actingAsPlatformAdmin();
    $this->postJson(onPlatform("tenants/{$this->acme->id}/reactivate"))
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    actingAsTenantUser($this->acme, $user);
    $this->getJson(onApiHost('/v1/me'))->assertOk();

    // Platform entries have no tenant; row-level security shows them in the central context only.
    tenancy()->end();
    expect(AuditLog::query()->whereIn('action', ['tenant.suspended', 'tenant.reactivated'])->count())->toBe(2);
});

it('records suspension only once', function (): void {
    actingAsPlatformAdmin();

    $this->postJson(onPlatform("tenants/{$this->acme->id}/suspend"))->assertOk();
    $this->postJson(onPlatform("tenants/{$this->acme->id}/suspend"))->assertOk();

    expect(AuditLog::query()->where('action', 'tenant.suspended')->count())->toBe(1);
});
