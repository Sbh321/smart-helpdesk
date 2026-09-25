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
use Illuminate\Support\Str;
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

        // The workspace finder (M5-02) mails whoever owns the address: three requests a minute per address and
        // client, and twenty an hour per client, so it can neither flood an inbox nor probe many addresses.
        RateLimiter::for('workspace-reminder', fn (Request $request): array => [
            Limit::perMinute(3)->by('workspace-reminder:'.$request->ip().'|'.hash('sha256', Str::lower((string) $request->input('email', '')))),
            Limit::perHour(20)->by('workspace-reminder-ip:'.$request->ip()),
        ]);

        // Every invitation sends a mail: twenty a minute per user keeps a mistake from flooding inboxes.
        RateLimiter::for('user-invitations', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('user-invitations:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
