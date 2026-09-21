<?php

declare(strict_types=1);

namespace App\Modules\Integrations\OAuth;

use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Integrations\Models\ApiClient;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\ScopeRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Scope rules of the token endpoint (docs/07-api/authentication.md §3).
 *
 * Passport silently drops requested scopes the client does not hold; integrators would then get a
 * token that fails later with 403. Here a requested scope must be one the client was granted, or
 * the request fails with `invalid_scope`. No scope requested means every scope the client holds.
 * The `*` wildcard does not exist.
 */
final class ClientScopeRepository extends ScopeRepository
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return ScopeMap::exists($identifier) ? new Scope($identifier) : null;
    }

    /**
     * @param  ScopeEntityInterface[]  $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $client = $this->clients->findActive($clientEntity->getIdentifier());

        if (! $client instanceof ApiClient || $grantType !== ApiClient::GRANT) {
            throw new OAuthServerException('Client authentication failed', 4, 'invalid_client', 401);
        }

        if ($scopes === []) {
            return array_map(fn (string $scope): Scope => new Scope($scope), $client->scopes);
        }

        foreach ($scopes as $scope) {
            if (! $client->hasScope($scope->getIdentifier())) {
                throw OAuthServerException::invalidScope($scope->getIdentifier());
            }
        }

        return array_values($scopes);
    }
}
