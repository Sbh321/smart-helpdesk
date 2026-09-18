<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * CSRF for the platform console with its own cookie and header names.
 *
 * The tenant `XSRF-TOKEN` cookie is set for the whole platform domain, while the platform cookie is
 * host-only on the admin host. Two cookies of the same name would shadow each other in the browser,
 * so the platform one is `XSRF-TOKEN-PLATFORM` and the header is `X-XSRF-TOKEN-PLATFORM`.
 */
final class ValidatePlatformCsrfToken extends ValidateCsrfToken
{
    public const COOKIE = 'XSRF-TOKEN-PLATFORM';

    public const HEADER = 'X-XSRF-TOKEN-PLATFORM';

    /**
     * @param  Request  $request
     */
    protected function getTokenFromRequest($request): ?string
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if (! $token && $header = $request->header(self::HEADER)) {
            try {
                $token = CookieValuePrefix::remove($this->encrypter->decrypt($header, self::serialized()));
            } catch (DecryptException) {
                $token = '';
            }
        }

        return is_string($token) ? $token : null;
    }

    /**
     * @param  Request  $request
     * @param  array<string, mixed>  $config
     */
    protected function newCookie($request, $config): Cookie
    {
        return new Cookie(
            self::COOKIE,
            $request->session()->token(),
            $this->availableAt(60 * (int) $config['lifetime']),
            $config['path'],
            $config['domain'],
            (bool) $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            (bool) ($config['partitioned'] ?? false),
        );
    }
}
