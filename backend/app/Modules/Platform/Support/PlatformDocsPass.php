<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Models\PlatformUser;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Access to the platform documentation (ADR-0024, roadmap M5-06).
 *
 * The platform session cookie is host-only on the admin host, so the platform-docs host cannot read it.
 * The console therefore hands the admin over with a link that is signed with the application key, lasts
 * a minute and works once; the platform-docs host exchanges it for its own host-only cookie (encrypted
 * by `EncryptCookies`), and the proxy checks that cookie on every request through `check()`.
 */
final readonly class PlatformDocsPass
{
    public function __construct(private Clock $clock) {}

    /**
     * The one-time link that signs this admin in to the platform documentation.
     *
     * @param  string  $next  a path on the platform-docs host to open afterwards
     */
    public function handoffUrl(PlatformUser $admin, string $next = '/'): string
    {
        $query = [
            'admin' => $admin->id,
            'expires' => $this->clock->now()->getTimestamp() + (int) config('helpdesk.platform.docs_handoff_seconds'),
            'nonce' => Str::random(32),
            'next' => self::safePath($next),
        ];
        $query['signature'] = $this->sign($query);

        return sprintf('https://%s/_session/start?%s', config('helpdesk.hosts.platform_docs'), http_build_query($query));
    }

    /**
     * The admin a hand-off link belongs to, if it is genuine, unexpired and unused; it is spent here.
     *
     * @param  array<string, mixed>  $query
     */
    public function redeem(array $query): ?PlatformUser
    {
        $fields = [
            'admin' => (string) ($query['admin'] ?? ''),
            'expires' => (int) ($query['expires'] ?? 0),
            'nonce' => (string) ($query['nonce'] ?? ''),
            'next' => (string) ($query['next'] ?? '/'),
        ];

        if (! hash_equals($this->sign($fields), (string) ($query['signature'] ?? ''))
            || $fields['expires'] < $this->clock->now()->getTimestamp()
            || strlen($fields['nonce']) !== 32
            // Single use: the nonce is remembered for longer than the link lives.
            || ! Cache::add('platform-docs-handoff:'.$fields['nonce'], true, 2 * (int) config('helpdesk.platform.docs_handoff_seconds'))) {
            return null;
        }

        return PlatformUser::query()->find($fields['admin']);
    }

    /** The cookie that lets this admin read the documentation until it expires. */
    public function cookieFor(PlatformUser $admin): Cookie
    {
        $minutes = (int) config('helpdesk.platform.docs_pass_minutes');
        $value = json_encode([
            'admin' => $admin->id,
            'expires' => $this->clock->now()->getTimestamp() + $minutes * 60,
        ], JSON_THROW_ON_ERROR);

        // Host-only (no domain), HTTPS only, not readable by scripts, sent on top-level navigation. Built
        // directly: Laravel's cookie() would fill the domain from session.domain (.<platform domain>) and
        // send the pass to every host. EncryptCookies still encrypts it on the way out.
        return Cookie::create(
            (string) config('helpdesk.platform.docs_cookie'),
            $value,
            $this->clock->now()->addMinutes($minutes),
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    /** The admin holding a valid pass, checked against the database on every request. */
    public function check(?string $cookie): ?PlatformUser
    {
        if ($cookie === null || $cookie === '') {
            return null;
        }

        $pass = json_decode($cookie, true);
        if (! is_array($pass) || ! is_string($pass['admin'] ?? null) || (int) ($pass['expires'] ?? 0) < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return PlatformUser::query()->find($pass['admin']);
    }

    /** Only a path on the same host: no scheme, no `//host`, no backslash. */
    public static function safePath(?string $path): string
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\')
            ? $path
            : '/';
    }

    /**
     * @param  array<string, int|string>  $fields
     */
    private function sign(array $fields): string
    {
        $payload = implode('|', [$fields['admin'], $fields['expires'], $fields['nonce'], $fields['next']]);

        return hash_hmac('sha256', 'platform-docs|'.$payload, (string) config('app.key'));
    }
}
