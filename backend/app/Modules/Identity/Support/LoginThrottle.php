<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Exceptions\AccountLocked;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Two layers (docs/07-api/authentication.md §1):
 *  - 5 attempts a minute per workspace, address and client (429 rate_limited, throttle middleware)
 *  - 10 failures lock the account for 15 minutes (403 account_locked)
 *
 * Both use the rate limiter, which keeps its own cache repository. The per-tenant cache tagging
 * applied inside tenancy therefore cannot hide a counter from the next request.
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 10;

    public const LOCK_MINUTES = 15;

    public function __construct(private readonly Request $request) {}

    public function ensureNotLocked(string $tenantId, string $email): void
    {
        $key = $this->counterKey($tenantId, $email);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $minutes = (int) max(1, ceil(RateLimiter::availableIn($key) / 60));

            throw AccountLocked::make($minutes);
        }
    }

    public function recordFailure(string $tenantId, string $email): void
    {
        RateLimiter::increment($this->counterKey($tenantId, $email), self::LOCK_MINUTES * 60);
    }

    public function clear(string $tenantId, string $email): void
    {
        RateLimiter::clear($this->counterKey($tenantId, $email));
    }

    /**
     * Key for the per-minute throttle; unlike the lockout it includes the client address.
     */
    public function rateLimitKey(string $workspace, string $email): string
    {
        return 'login:'.Str::lower($workspace.'|'.$email.'|'.$this->request->ip());
    }

    private function counterKey(string $tenantId, string $email): string
    {
        return 'login-failures:'.$tenantId.':'.Str::lower($email);
    }
}
