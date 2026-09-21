<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Models\User;
use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Support\Auth\ApiClientPrincipal;
use Carbon\CarbonImmutable;
use Database\Factories\ApiClientFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Laravel\Passport\Client;

/**
 * A workspace's API client (docs/07-api/authentication.md §3): a Passport client-credentials
 * client bound to one tenant, and the principal the `api` guard authenticates.
 *
 * As a principal it holds the scopes of the access token in use; `Gate::before` in the
 * Integrations provider answers every ability from those scopes through `ScopeMap`, so a client
 * never gets a permission a scope does not name, whatever the policies say.
 *
 * `BelongsToTenant` scopes management queries to the current workspace. The token endpoint runs
 * before any tenant is known, where the scope is inactive, which is why `oauth_clients` is a
 * credential table (no row-level security) rather than a primary one.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property list<string> $scopes
 * @property bool $revoked
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 */
final class ApiClient extends Client implements ApiClientPrincipal, AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
    use BelongsToTenant;

    public const GRANT = 'client_credentials';

    /**
     * Scopes of the access token this request authenticated with; null outside a token request.
     *
     * @var list<string>|null
     */
    private ?array $tokenScopes = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grant_types' => 'array',
            'scopes' => 'array',
            'redirect_uris' => 'array',
            'revoked' => 'bool',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function clientId(): string
    {
        return $this->id;
    }

    /**
     * @param  list<string>  $scopes
     */
    public function withTokenScopes(array $scopes): static
    {
        $this->tokenScopes = $scopes;

        return $this;
    }

    /**
     * The scopes in force for this request: the token's, limited to what the client still holds.
     *
     * @return list<string>
     */
    public function activeScopes(): array
    {
        return array_values(array_intersect($this->tokenScopes ?? [], $this->scopes));
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return ScopeMap::permissionsFor($this->activeScopes());
    }

    public function grantsPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasGrantType(string $grantType): bool
    {
        return $grantType === self::GRANT;
    }

    /**
     * Clients have no password; the secret is Passport's and is checked by the token endpoint.
     */
    public function getAuthPasswordName(): string
    {
        return 'secret';
    }

    protected static function newFactory(): Factory
    {
        return ApiClientFactory::new();
    }
}
