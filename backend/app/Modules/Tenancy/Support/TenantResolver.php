<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * The tenant of the bearer token, read before the token's principal is loaded: a Sanctum token
     * (`<id>|<secret>`) or a Passport client-credentials JWT, whose `jti` names the stored
     * `oauth_access_tokens` row. The JWT is not verified here; the `api` guard verifies it next
     * and loads the client inside this tenant only, so a forged token names a tenant and still
     * fails authentication.
     */
    public function bearerTenantId(Request $request): ?string
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        if (str_contains($token, '|')) {
            return PersonalAccessToken::findToken($token)?->tenant_id;
        }

        $tokenId = $this->jwtId($token);

        if ($tokenId === null) {
            return null;
        }

        $tenantId = DB::table('oauth_access_tokens')->where('id', $tokenId)->where('revoked', false)->value('tenant_id');

        return is_string($tenantId) ? $tenantId : null;
    }

    private function jwtId(string $token): ?string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $id = is_array($payload) ? ($payload['jti'] ?? null) : null;

        return is_string($id) && preg_match('/^[0-9a-f]{80}$/', $id) === 1 ? $id : null;
    }

    public function find(string $id): ?Tenant
    {
        return Tenant::query()->find($id);
    }
}
