<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Modules\Integrations\Auth\ApiClientGuard;
use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Integrations\Http\Controllers\AccessTokenController;
use App\Modules\Integrations\Http\Middleware\AllowApiClients;
use App\Modules\Integrations\Http\Middleware\EnsureIdempotency;
use App\Modules\Integrations\Models\AccessToken;
use App\Modules\Integrations\Models\ApiClient;
use App\Modules\Integrations\OAuth\ClientScopeRepository;
use App\Modules\Integrations\OAuth\TenantClientRepository;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Time\Clock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\ResourceServer;

/**
 * API clients over Passport's client-credentials grant (ADR-0007, docs/07-api/authentication.md §3).
 *
 * Passport provides the authorization server (JWT signing, secret hashing, token persistence) and
 * the resource server (JWT validation). Everything tenant- or permission-shaped is ours: the
 * client and token models, the `api` guard, scope checks at the token endpoint, the scope →
 * permission gate and the route opt-in for clients.
 */
final class IntegrationsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Only POST /oauth/token is exposed, registered below; Passport's own routes (authorize,
        // device flow, token refresh) are not.
        Passport::ignoreRoutes();

        $this->app->singleton(ClientRepository::class, TenantClientRepository::class);
        $this->app->bind(ScopeRepository::class, ClientScopeRepository::class);
    }

    protected function bootModule(): void
    {
        Passport::useClientModel(ApiClient::class);
        Passport::useTokenModel(AccessToken::class);
        Passport::tokensCan(ScopeMap::DESCRIPTIONS);
        Passport::tokensExpireIn(now()->addHour());
        Passport::clientCredentialsTokensExpireIn(now()->addHour());

        Auth::extend('api-client', function (Application $app): ApiClientGuard {
            $guard = new ApiClientGuard(
                fn (): ResourceServer => $app->make(ResourceServer::class),
                $app->make('events'),
                $app->make(Clock::class),
                $app->make('request'),
            );
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        // A client holds exactly the permissions its token's scopes map to, and nothing through
        // roles or policies (authentication.md §Scopes → permissions).
        Gate::before(fn (mixed $user, string $ability): ?bool => $user instanceof ApiClient
            ? $user->grantsPermission($ability)
            : null);

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware(AllowApiClients::ALIAS, AllowApiClients::class);
        $router->aliasMiddleware(EnsureIdempotency::ALIAS, EnsureIdempotency::class);

        // Token requests: 10 a minute per client id (per address when none is given).
        RateLimiter::for('oauth-token', function (Request $request): Limit {
            $clientId = $request->input('client_id', $request->getUser());

            return Limit::perMinute(10)->by('oauth-token:'.(is_string($clientId) && $clientId !== '' ? $clientId : $request->ip()));
        });

        // API calls: 120 a minute per client; SPA users are not limited here.
        RateLimiter::for('api-clients', function (Request $request): Limit {
            $user = $request->user();

            return $user instanceof ApiClient
                ? Limit::perMinute(120)->by("rl:{$user->tenant_id}:client:{$user->id}")
                : Limit::none();
        });

        if (! $this->app->routesAreCached()) {
            $route = Route::middleware(['api', 'throttle:oauth-token']);

            if (config('helpdesk.host_layout') === 'split') {
                $route->domain((string) config('helpdesk.hosts.api'));
            }

            $route->post('/oauth/token', AccessTokenController::class)->name('oauth.token');
        }
    }
}
