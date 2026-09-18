<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Finds the tenant for a request from trusted sources only (ADR-0021 §Tenant resolution).
 */
final class TenantResolver
{
    public const SESSION_KEY = 'tenant_id';

    public function singleTenant(): ?Tenant
    {
        $slug = config('helpdesk.single_tenant');

        return is_string($slug) && $slug !== '' ? Tenant::findBySlug($slug) : null;
    }

    public function singleTenantMode(): bool
    {
        $slug = config('helpdesk.single_tenant');

        return is_string($slug) && $slug !== '';
    }

    /**
     * The tenant stored in the session at login, if any.
     */
    public function sessionTenantId(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The tenant of the API token, read before the token's user is loaded.
     * MVP-SHORTCUT: Sanctum tokens only; V1: none (Passport client tenants are added in M3-04).
     */
    public function bearerTenantId(Request $request): ?string
    {
        $token = $request->bearerToken();

        if ($token === null || ! str_contains($token, '|')) {
            return null;
        }

        return PersonalAccessToken::findToken($token)?->tenant_id;
    }

    public function find(string $id): ?Tenant
    {
        return Tenant::query()->find($id);
    }
}
