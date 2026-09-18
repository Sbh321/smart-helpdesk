<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantModelInventory;

/*
 * Item 1 (data half) of the mandatory isolation suite (docs/10-quality/testing.md): for every
 * primary model, seed rows in tenants A and B, query under A and prove that B is invisible and
 * unreachable, and that create() stamps A.
 *
 * The dataset is the PRIMARY list of Tests\Support\TenantModelInventory, minus the models that
 * have no factory yet, so every model added in M2 is covered the moment it is classified and
 * seedable. Row-level security is the second line of defence and is asserted from M3-07 on (see
 * SchemaTest.php); everything here is the Eloquent global scope.
 */

dataset('primary models', fn (): array => TenantModelInventory::seedablePrimary());

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
});

/**
 * @param  class-string<Model>  $model
 */
function seedForTenant(string $model, mixed $tenant): Model
{
    return $model::factory()->forTenant($tenant)->create();
}

it('returns no rows of another tenant', function (string $model): void {
    $mine = seedForTenant($model, $this->acme);
    $theirs = seedForTenant($model, $this->globex);

    tenancy()->initialize($this->acme);

    $keys = $model::query()->pluck((new $model)->getKeyName())->all();

    expect($keys)->toContain($mine->getKey())
        ->and($keys)->not->toContain($theirs->getKey())
        ->and($model::query()->count())->toBe(1);
})->with('primary models');

it('cannot read, update or delete another tenant row by its primary key', function (string $model): void {
    $theirs = seedForTenant($model, $this->globex);

    tenancy()->initialize($this->acme);

    expect($model::query()->find($theirs->getKey()))->toBeNull()
        ->and($model::query()->whereKey($theirs->getKey())->exists())->toBeFalse()
        ->and($model::query()->whereKey($theirs->getKey())->delete())->toBe(0);

    tenancy()->end();

    expect($model::query()->withoutTenancy()->whereKey($theirs->getKey())->exists())->toBeTrue();
})->with('primary models');

it('fills tenant_id from the current tenant on create', function (string $model): void {
    tenancy()->initialize($this->acme);

    $created = $model::factory()->create();

    expect($created->tenant_id)->toBe($this->acme->getKey())
        ->and(DB::table(TenantModelInventory::tableOf($model))
            ->where((new $model)->getKeyName(), $created->getKey())
            ->value('tenant_id'))->toBe($this->acme->getKey());
})->with('primary models');

it('refuses to create a row outside a tenant, so a factory without a tenant fails loudly', function (string $model): void {
    expect(fn () => $model::factory()->create())
        ->toThrow(QueryException::class, 'tenant_id');
})->with('primary models');

it('never lets a query in tenant A reach a row of tenant B through a where clause', function (string $model): void {
    $theirs = seedForTenant($model, $this->globex);

    tenancy()->initialize($this->acme);

    // The global scope is ANDed onto every builder, so naming the other tenant explicitly is inert.
    expect($model::query()->where('tenant_id', $this->globex->getKey())->count())->toBe(0)
        ->and($model::query()->whereKey($theirs->getKey())->orWhere('tenant_id', $this->globex->getKey())->count())->toBe(0);
})->with('primary models');

afterEach(function (): void {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});
