<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Console\SyncPermissionsCommand;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Session\TenantAwareDatabaseSessionHandler;
use App\Modules\Identity\Support\LoginThrottle;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\Sanctum;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class]);
        }

        // Overrides the built-in database driver so session rows carry tenant_id and guard.
        Session::extend('database', fn ($app) => new TenantAwareDatabaseSessionHandler(
            $app->make('db')->connection($app['config']['session.connection']),
            $app['config']['session.table'],
            $app['config']['session.lifetime'],
            $app,
        ));

        // Five sign-in attempts a minute per workspace, address and client (authentication.md §1).
        RateLimiter::for('login', function (Request $request): Limit {
            $throttle = new LoginThrottle($request);
            $key = $throttle->rateLimitKey(
                (string) $request->input('workspace', ''),
                (string) $request->input('email', ''),
            );

            return Limit::perMinute(5)->by($key);
        });
    }
}
