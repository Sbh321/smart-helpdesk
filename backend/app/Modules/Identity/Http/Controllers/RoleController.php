<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalogue;
use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoleIsSystem extends DomainException
{
    public static function make(): self
    {
        return new self('Default roles cannot be changed. Create a custom role instead.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Forbidden;
    }
}

/**
 * Roles a workspace can use: the global defaults plus its own custom roles (ADR-0007).
 */
#[Group('Roles and permissions')]
final class RoleController
{
    /**
     * List the roles available in this workspace.
     */
    public function index(): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->with('permissions')
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', tenant()?->getTenantKey()))
            ->orderBy('tenant_id')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    /**
     * The permission catalogue, grouped by resource.
     */
    public function permissions(): JsonResponse
    {
        return new JsonResponse(['data' => PermissionCatalogue::PERMISSIONS]);
    }

    /**
     * Create a custom role.
     */
    #[Response(status: 201, type: RoleResource::class)]
    public function store(RoleRequest $request): JsonResponse
    {
        $role = Role::query()->create([
            'name' => $request->validated('name'),
            'guard_name' => PermissionCatalogue::GUARD,
            'tenant_id' => tenant()?->getTenantKey(),
            'is_system' => false,
        ]);

        $role->syncPermissions($request->validated('permissions'));
        Audit::record('role.created', $role, ['permissions' => $request->validated('permissions')]);

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    /**
     * Change a custom role's name or permissions.
     */
    public function update(RoleRequest $request, Role $role): RoleResource
    {
        $this->ensureCustom($role);

        $before = $role->permissions->pluck('name')->all();

        if ($request->has('name')) {
            $role->forceFill(['name' => $request->validated('name')])->save();
        }

        if ($request->has('permissions')) {
            $role->syncPermissions($request->validated('permissions'));
            Audit::record('role.permissions_changed', $role, ['before' => $before, 'after' => $request->validated('permissions')]);
        }

        return new RoleResource($role->load('permissions'));
    }

    /**
     * Delete a custom role.
     */
    public function destroy(Role $role): JsonResponse
    {
        $this->ensureCustom($role);

        $role->delete();
        Audit::record('role.deleted', $role);

        return new JsonResponse(status: 204);
    }

    private function ensureCustom(Role $role): void
    {
        abort_if($role->tenant_id !== tenant()?->getTenantKey(), 404);

        if ($role->is_system) {
            throw RoleIsSystem::make();
        }
    }
}
