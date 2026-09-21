<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
 * Tenancy helpers for feature tests (docs/10-quality/testing.md §Conventions).
 * loginToWorkspace() through the real login endpoint arrives with M1-08.
 */

function createTenant(string $slug = 'acme', array $attributes = []): Tenant
{
    return Tenant::factory()->slug($slug)->create($attributes);
}

function createTenantUser(Tenant $tenant, array $attributes = []): User
{
    return User::factory()->forTenant($tenant)->create($attributes);
}

/**
 * Acts as a tenant user with a session that carries the tenant id, as login will store it.
 *
 * The test body continues inside the tenant, so its own reads and writes pass row-level security
 * (M3-07) the way the requests under test do; every request still starts in the central context
 * (Tests\TestCase::call()). Rows of another workspace are read with `$other->run(fn () => …)`.
 *
 * The Origin header makes Sanctum treat the request as coming from the SPA, so the session
 * middleware runs and the resolver can read the session tenant, exactly as in the browser.
 */
function actingAsTenantUser(Tenant $tenant, ?User $user = null): User
{
    $user ??= createTenantUser($tenant);

    test()->actingAs($user, 'sanctum')
        ->withSession([TenantResolver::SESSION_KEY => $tenant->getKey()])
        ->withHeader('Origin', 'https://'.config('helpdesk.hosts.app'));

    // After actingAs(), so the connection's actor is this user, as in a request.
    tenancy()->initialize($tenant);
    app(RlsTenancyBootstrapper::class)->refreshActor();

    return $user;
}

/**
 * A request that carries a session but no authenticated user.
 */
function fromSpaOrigin(): void
{
    test()->withHeader('Origin', 'https://'.config('helpdesk.hosts.app'));
}

function onApiHost(string $path): string
{
    return 'https://'.config('helpdesk.hosts.api').'/'.ltrim($path, '/');
}

/**
 * Acts as a tenant user holding one of the default roles (docs/03-architecture/security.md).
 * Syncs the permission catalogue first, because the test database starts empty.
 */
function actingAsRole(Tenant $tenant, string $role = 'agent', ?User $user = null): User
{
    app(SyncPermissionCatalogue::class)();

    $user = actingAsTenantUser($tenant, $user);
    $tenant->run(fn () => $user->syncRoles([$role]));

    return $user;
}

/**
 * Finds a row in whichever workspace holds it. Row-level security (M3-07) shows a connection one
 * workspace at a time, so a test that only has an id looks in each; the model comes back loaded.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @param  class-string<TModel>  $model
 * @return TModel
 */
function findInAnyTenant(string $model, string $id): Model
{
    foreach (Tenant::query()->cursor() as $tenant) {
        $found = $tenant->run(fn () => $model::query()->find($id));

        if ($found !== null) {
            return $found;
        }
    }

    throw (new ModelNotFoundException)->setModel($model, [$id]);
}
