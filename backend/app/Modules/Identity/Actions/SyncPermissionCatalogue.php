<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the catalogue and the global default roles into the database. Idempotent: it adds what is
 * missing and re-syncs each default role's permissions, leaving tenant custom roles alone.
 */
final class SyncPermissionCatalogue
{
    /**
     * @return array{permissions: int, roles: int}
     */
    public function __invoke(): array
    {
        // Global roles have no tenant; row-level security lets only the central context write them
        // (M3-07), so a caller inside a workspace steps out for the sync and back in afterwards.
        return tenancy()->central(fn (): array => DB::transaction(function (): array {
            foreach (PermissionCatalogue::all() as $name) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => PermissionCatalogue::GUARD]);
            }

            foreach (PermissionCatalogue::roles() as $role => $permissions) {
                /** @var Role $model */
                $model = Role::query()->firstOrCreate(
                    ['name' => $role, 'guard_name' => PermissionCatalogue::GUARD, 'tenant_id' => null],
                    ['is_system' => true],
                );

                $model->syncPermissions($permissions);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return [
                'permissions' => count(PermissionCatalogue::all()),
                'roles' => count(PermissionCatalogue::roles()),
            ];
        }));
    }
}
