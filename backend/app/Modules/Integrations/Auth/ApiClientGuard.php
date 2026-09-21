<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Auth;

use App\Modules\Integrations\Models\ApiClient;
use App\Support\Time\Clock;
use Closure;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * The `api` guard: a Passport client-credentials bearer token → the `ApiClient` principal
 * (docs/07-api/authentication.md §3).
 *
 * Passport's own TokenGuard only ever returns users, and client-credentials tokens have none, so
 * `auth:api` and `can:` could not work with it. This guard validates the JWT with Passport's
 * resource server (signature, expiry, token not revoked), then loads the client inside the
 * current tenant scope and refuses revoked clients on every request: revocation has no grace
 * period. A client of another workspace is simply not found, which answers 401.
 */
final class ApiClientGuard implements Guard
{
    use GuardHelpers;

    public const NAME = 'api';

    /** `last_used_at` is written at most this often, so reads do not become writes. */
    private const TOUCH_AFTER_SECONDS = 300;

    /**
     * @param  Closure(): ResourceServer  $server  resolved on the first bearer request, so SPA
     *                                             requests never read the signing key
     */
    public function __construct(
        private readonly Closure $server,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
        private Request $request,
    ) {}

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $client = $this->authenticate();

        if ($client !== null) {
            $this->setUser($client);
            $this->events->dispatch(new Authenticated(self::NAME, $client));
        }

        return $client;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setRequest(Request $request): static
    {
        if ($request !== $this->request) {
            $this->request = $request;
            $this->user = null;
        }

        return $this;
    }

    private function authenticate(): ?ApiClient
    {
        $token = $this->request->bearerToken();

        // Sanctum tokens look like "<id>|<secret>"; a JWT has three dot-separated parts.
        if ($token === null || substr_count($token, '.') !== 2) {
            return null;
        }

        try {
            $psr = ($this->server)()->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($this->request));
        } catch (OAuthServerException) {
            return null;
        }

        $clientId = $psr->getAttribute('oauth_client_id');
        $userId = $psr->getAttribute('oauth_user_id');

        // Only client-credentials tokens: no user, or the client itself as subject.
        if (! is_string($clientId) || ($userId !== null && $userId !== '' && $userId !== $clientId)) {
            return null;
        }

        $client = ApiClient::query()->whereKey($clientId)->where('revoked', false)->first();

        if ($client === null) {
            return null;
        }

        $scopes = $psr->getAttribute('oauth_scopes');
        $client->withTokenScopes(is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : []);
        $this->touch($client);

        return $client;
    }

    private function touch(ApiClient $client): void
    {
        $now = $this->clock->now();

        if ($client->last_used_at === null || $client->last_used_at->diffInSeconds($now) >= self::TOUCH_AFTER_SECONDS) {
            ApiClient::query()->whereKey($client->id)->update(['last_used_at' => $now]);
            $client->setAttribute('last_used_at', $now);
            $client->syncOriginalAttribute('last_used_at');
        }
    }
}
