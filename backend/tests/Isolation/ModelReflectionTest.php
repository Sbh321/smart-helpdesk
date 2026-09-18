<?php

declare(strict_types=1);

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;
use Tests\Support\TenantModelInventory;

/*
 * Item 1 of the mandatory isolation suite (docs/10-quality/testing.md §Mandatory tenancy isolation
 * suite): every Eloquent model is classified exactly once, and its classification agrees with the
 * trait it uses and with the table registry in App\Modules\Tenancy\Support\TenantTables.
 *
 * Models are discovered on disk, so a model added anywhere under app/ without a list entry fails
 * here, and a model with a tenant_id column that is not scoped fails too.
 */

/**
 * True while a table listed in AWAITING_REGISTRATION is genuinely still missing from the registry;
 * the moment the owning task adds it, the assertions that call this start enforcing again.
 */
function awaitingRegistration(string $table): bool
{
    return in_array($table, TenantModelInventory::AWAITING_REGISTRATION, true)
        && ! in_array($table, [...TenantTables::all(), ...TenantTables::CREDENTIALS], true);
}

it('classifies every Eloquent model in exactly one list', function (): void {
    $classified = TenantModelInventory::classified();
    $discovered = TenantModelInventory::discover();

    $unclassified = array_values(array_diff($discovered, array_keys($classified)));
    $stale = array_values(array_diff(array_keys($classified), $discovered));
    $twice = array_keys(array_filter($classified, fn (array $lists): bool => count($lists) > 1));

    expect($unclassified)->toBe([], 'Add these models to one list in Tests\Support\TenantModelInventory: '.implode(', ', $unclassified))
        ->and($stale)->toBe([], 'These models no longer exist: '.implode(', ', $stale))
        ->and($twice)->toBe([], 'These models are classified more than once: '.implode(', ', $twice));
});

it('keeps models in a Models directory so the registry and the schema stay comparable', function (): void {
    foreach (TenantModelInventory::discover() as $model) {
        expect($model)->toMatch('/^App\\\\(Models|Modules\\\\[A-Za-z]+\\\\Models)\\\\[A-Za-z]+$/');
    }
});

it('gives every primary model the BelongsToTenant trait', function (): void {
    foreach (TenantModelInventory::PRIMARY as $model) {
        expect(in_array(BelongsToTenant::class, class_uses_recursive($model), true))
            ->toBeTrue("{$model} is listed as primary but does not use BelongsToTenant.");
    }
});

it('gives every secondary model the BelongsToPrimaryModel trait', function (): void {
    foreach (TenantModelInventory::SECONDARY as $model) {
        expect(in_array(BelongsToPrimaryModel::class, class_uses_recursive($model), true))
            ->toBeTrue("{$model} is listed as secondary but does not use BelongsToPrimaryModel.");
    }
})->skip(TenantModelInventory::SECONDARY === [], 'No secondary models before M2 (ticket comments, events, timers).');

it('never scopes a control-plane model', function (): void {
    foreach (TenantModelInventory::CENTRAL as $model) {
        $traits = class_uses_recursive($model);

        expect($traits)->not->toContain(BelongsToTenant::class)
            ->and($traits)->not->toContain(BelongsToPrimaryModel::class);
    }
});

it('registers the table of every tenant-scoped model in TenantTables', function (): void {
    foreach ([...TenantModelInventory::PRIMARY, ...TenantModelInventory::SECONDARY] as $model) {
        $table = TenantModelInventory::tableOf($model);

        if (awaitingRegistration($table)) {
            continue;
        }

        expect(TenantTables::PRIMARY)->toContain($table);
    }

    foreach (TenantModelInventory::NULLABLE as $model) {
        expect(TenantTables::NULLABLE)->toContain(TenantModelInventory::tableOf($model));
    }

    foreach (TenantModelInventory::CREDENTIALS as $model) {
        expect(TenantTables::CREDENTIALS)->toContain(TenantModelInventory::tableOf($model));
    }
});

it('keeps control-plane tables out of the tenant registry', function (): void {
    foreach (TenantModelInventory::CENTRAL as $model) {
        expect(TenantTables::all())->not->toContain(TenantModelInventory::tableOf($model))
            ->and(TenantTables::CREDENTIALS)->not->toContain(TenantModelInventory::tableOf($model));
    }
});

it('never leaves a model with a tenant_id column unscoped', function (): void {
    foreach (TenantModelInventory::discover() as $model) {
        $table = TenantModelInventory::tableOf($model);

        if (! Schema::hasColumn($table, 'tenant_id')) {
            continue;
        }

        $scoped = in_array($table, [...TenantTables::all(), ...TenantTables::CREDENTIALS], true)
            || in_array($table, TenantModelInventory::UNSCOPED_TABLES_WITH_TENANT_ID, true)
            || awaitingRegistration($table);

        expect($scoped)->toBeTrue(
            "{$table} has a tenant_id column but is in neither TenantTables nor the documented "
            .'control-plane allow-list in Tests\Support\TenantModelInventory.'
        );
    }
});
