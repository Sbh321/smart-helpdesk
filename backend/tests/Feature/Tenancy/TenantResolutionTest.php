<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $report = fn () => [
        'tenant' => tenant()?->getTenantKey(),
        'setting' => (string) DB::selectOne("SELECT current_setting('app.current_tenant', true) AS value")->value,
        'context' => Context::get('tenant_id'),
        'user' => auth('sanctum')->id(),
    ];

    Route::middleware(['api', 'tenant'])->prefix('v1')->group(function () use ($report): void {
        Route::get('/test-tenancy/whoami', $report);
    });
    Route::middleware(['api', 'tenant.guest'])->prefix('v1')->group(function () use ($report): void {
        Route::post('/test-tenancy/login', $report);
    });
    Route::middleware(['api', 'platform'])->prefix('platform-api')->group(function (): void {
        Route::get('/test-tenancy', fn () => ['tenant' => tenant()?->getTenantKey()]);
    });

    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
});

describe('authenticated tenant routes', function (): void {
    it('run in the session tenant with the database setting applied', function (): void {
        $user = actingAsTenantUser($this->acme);

        $this->getJson('/v1/test-tenancy/whoami')
            ->assertOk()
            ->assertExactJson([
                'tenant' => $this->acme->id,
                'setting' => $this->acme->id,
                'context' => $this->acme->id,
                'user' => $user->id,
            ]);
    });

    it('end tenancy after the request', function (): void {
        actingAsTenantUser($this->acme);

        $this->getJson('/v1/test-tenancy/whoami')->assertOk();

        expect(tenancy()->initialized)->toBeFalse()
            ->and(DB::selectOne("SELECT current_setting('app.current_tenant', true) AS value")->value)->toBe('');
    });

    it('reject a session whose tenant differs from the user tenant and log it', function (): void {
        Log::spy();
        $user = createTenantUser($this->acme);
        $this->actingAs($user, 'sanctum')
            ->withSession(['tenant_id' => $this->globex->id])
            ->withHeader('Origin', 'https://'.config('helpdesk.hosts.app'));

        $this->getJson('/v1/test-tenancy/whoami')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        Log::shouldHaveReceived('critical')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'tenancy.membership_mismatch'
                && $context['resolved_tenant_id'] === $this->globex->id
                && $context['user_tenant_id'] === $this->acme->id,
        );
    });

    it('reject an authenticated user when the session has no tenant', function (): void {
        $this->actingAs(createTenantUser($this->acme), 'sanctum');

        $this->getJson('/v1/test-tenancy/whoami')->assertUnauthorized();
    });

    it('reject guests', function (): void {
        $this->getJson('/v1/test-tenancy/whoami')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    });

    it('reject a session that points at a deleted tenant', function (): void {
        fromSpaOrigin();
        $this->withSession(['tenant_id' => '0199aaaa-0000-7000-8000-00000000dead']);

        $this->getJson('/v1/test-tenancy/whoami')->assertUnauthorized();
    });

    it('answer 403 tenant_suspended for a suspended tenant', function (): void {
        $user = actingAsTenantUser($this->acme);
        $this->acme->update(['status' => 'suspended']);

        $this->getJson('/v1/test-tenancy/whoami')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_suspended');
    });

    it('resolve the tenant from a bearer token before loading the user', function (): void {
        $user = createTenantUser($this->acme);
        $token = $user->createToken('integration');
        $token->accessToken->forceFill(['tenant_id' => $this->acme->id])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/v1/test-tenancy/whoami')
            ->assertOk()
            ->assertJsonPath('tenant', $this->acme->id)
            ->assertJsonPath('user', $user->id);

        expect($token->plainTextToken)->toMatch('/^[0-9a-f-]{36}\|/');
    });

    it('reject a bearer token without a tenant', function (): void {
        $token = createTenantUser($this->acme)->createToken('integration');

        $this->withToken($token->plainTextToken)->getJson('/v1/test-tenancy/whoami')->assertUnauthorized();
    });
});

describe('pre-authentication routes', function (): void {
    it('initialise the workspace named in the body', function (): void {
        $this->postJson('/v1/test-tenancy/login', ['workspace' => 'acme'])
            ->assertOk()
            ->assertJsonPath('tenant', $this->acme->id)
            ->assertJsonPath('setting', $this->acme->id);
    });

    it('answer unknown, archived, missing or malformed workspaces like wrong credentials', function (mixed $workspace): void {
        Tenant::factory()->slug('old-co')->archived()->create();

        $this->postJson('/v1/test-tenancy/login', ['workspace' => $workspace])
            ->assertUnauthorized()
            ->assertJson([
                'code' => 'invalid_credentials',
                'detail' => 'The workspace, email or password is incorrect.',
            ]);
    })->with([
        'unknown' => 'initech',
        'archived' => 'old-co',
        'missing' => null,
        'malformed' => "acme'; --",
        'array' => [['acme']],
    ]);

    it('answer 403 for a suspended workspace', function (): void {
        Tenant::factory()->slug('paused')->suspended()->create();

        $this->postJson('/v1/test-tenancy/login', ['workspace' => 'paused'])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_suspended');
    });

    it('match the workspace case-insensitively', function (): void {
        $this->postJson('/v1/test-tenancy/login', ['workspace' => 'ACME'])->assertOk()->assertJsonPath('tenant', $this->acme->id);
    });
});

describe('single-tenant mode', function (): void {
    beforeEach(fn () => config(['helpdesk.single_tenant' => 'acme']));

    it('resolves the configured tenant for any host and ignores the session tenant', function (): void {
        $user = createTenantUser($this->acme);
        $this->actingAs($user, 'sanctum')
            ->withSession(['tenant_id' => $this->globex->id])
            ->withHeader('Origin', 'https://'.config('helpdesk.hosts.app'));

        $this->getJson('https://helpdesk.corp.example/v1/test-tenancy/whoami')
            ->assertOk()
            ->assertJsonPath('tenant', $this->acme->id);
    });

    it('ignores the workspace in the body', function (): void {
        $this->postJson('/v1/test-tenancy/login', ['workspace' => 'globex'])
            ->assertOk()
            ->assertJsonPath('tenant', $this->acme->id);
    });
});

it('never runs platform routes inside a tenant', function (): void {
    tenancy()->initialize($this->acme);

    $this->getJson('/platform-api/test-tenancy')->assertOk()->assertExactJson(['tenant' => null]);
});
