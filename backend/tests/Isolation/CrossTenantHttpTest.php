<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Item 2 of the mandatory isolation suite (docs/10-quality/testing.md): another tenant's
 * identifier must answer 404 — never 403, never the row — and a principal whose tenant does not
 * match the resolved tenant must answer 401.
 *
 * The middleware-level negatives (session tenant ≠ user tenant, missing session tenant, suspended
 * tenant, unknown workspace, host spoofing) are proved once in
 * tests/Feature/Tenancy/TenantResolutionTest.php; this file reuses its route-registration pattern
 * and adds only the resource-level cases, which need a bound model.
 *
 * `users` is the one tenant resource with a route binding in M1; the ticket, contact and comment
 * routes of M2 join the dataset by adding their route names below.
 */

beforeEach(function (): void {
    Route::middleware(['api', 'tenant'])->prefix('v1/test-isolation')->group(function (): void {
        Route::get('/users', fn (): array => ['ids' => User::query()->pluck('id')->all()]);
        Route::get('/users/{user}', fn (User $user): array => ['id' => $user->id]);
        Route::patch('/users/{user}', function (User $user): array {
            $user->update(['name' => 'Renamed by another tenant']);

            return ['id' => $user->id];
        });
    });

    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->theirs = createTenantUser($this->globex, ['name' => 'Globex Person']);
});

it('answers 404 for a GET on another tenant identifier', function (): void {
    actingAsTenantUser($this->acme);

    $this->getJson('/v1/test-isolation/users/'.$this->theirs->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('answers 404 for a write on another tenant identifier and leaves the row untouched', function (): void {
    actingAsTenantUser($this->acme);

    $this->patchJson('/v1/test-isolation/users/'.$this->theirs->id)->assertNotFound();

    expect($this->globex->run(fn () => $this->theirs->fresh())->name)->toBe('Globex Person');
});

it('makes a cross-tenant identifier indistinguishable from one that does not exist', function (): void {
    actingAsTenantUser($this->acme);

    $strip = fn (array $body): array => Arr::except($body, ['instance', 'request_id']);

    $crossTenant = $this->getJson('/v1/test-isolation/users/'.$this->theirs->id)->assertNotFound();
    $unknown = $this->getJson('/v1/test-isolation/users/'.Str::uuid7())->assertNotFound();

    expect($strip($crossTenant->json()))->toBe($strip($unknown->json()));
});

it('lists only the rows of the session tenant', function (): void {
    $mine = actingAsTenantUser($this->acme);

    $this->getJson('/v1/test-isolation/users')
        ->assertOk()
        ->assertExactJson(['ids' => [$mine->id]]);
});

it('answers 401 for a bearer token whose tenant is not the owner tenant', function (): void {
    $token = createTenantUser($this->acme)->createToken('integration');
    $token->accessToken->forceFill(['tenant_id' => $this->globex->getKey()])->save();

    $this->withToken($token->plainTextToken)
        ->getJson('/v1/test-isolation/users/'.$this->theirs->id)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('answers 401 when the session tenant is swapped for another live tenant', function (): void {
    $user = actingAsTenantUser($this->acme);

    // The attacker edits the decrypted session payload to name a tenant they can see resources of.
    $this->withSession(['tenant_id' => $this->globex->getKey()]);

    $this->getJson('/v1/test-isolation/users/'.$user->id)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});
