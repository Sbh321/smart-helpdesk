<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Models\PlatformUser;
use App\Support\Time\Clock;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Access to the platform-only hosts, the platform documentation and monitoring (ADR-0024, roadmap M5-06).
 *
 * The platform session cookie is host-only on the admin host, so those hosts cannot read it. The console
 * hands the admin over with a link that is signed with the application key, lasts a minute and works
 * once; the target host exchanges it for its own host-only cookie holding a random pass token, and the
 * proxy (and Horizon's gate) check that token on every request. The token is valid only while the server
 * holds it: at most `pass_minutes`, and it is deleted when the admin signs out of the console, because the
 * console session remembers the tokens it handed out.
 */
final readonly class PlatformPass
{
    public const array TARGETS = ['docs' => 'platform_docs', 'monitor' => 'monitor'];

    private const string SESSION_KEY = 'platform.passes';

    public function __construct(private Clock $clock) {}

    /** The host of a target ('docs' or 'monitor'). */
    public static function host(string $target): string
    {
        return (string) config('helpdesk.hosts.'.self::TARGETS[$target]);
    }

    /**
     * The one-time link that signs this admin in to a platform host. The pass token is created now and
     * recorded in the console session, so signing out of the console ends it.
     */
    public function handoffUrl(PlatformUser $admin, string $target, string $next, Session $console): string
    {
        $token = Str::random(48);
        // A lifetime, not an instant from Clock: the cache store expires entries on real time.
        Cache::put(self::cacheKey($token), $admin->id, self::minutes() * 60);
        $console->push(self::SESSION_KEY, $token);

        $query = [
            'token' => $token,
            'expires' => $this->clock->now()->getTimestamp() + (int) config('helpdesk.platform.pass_handoff_seconds'),
            'nonce' => Str::random(32),
            'next' => self::safePath($next),
        ];
        $query['signature'] = $this->sign(self::host($target), $query);

        return sprintf('https://%s/_session/start?%s', self::host($target), http_build_query($query));
    }

    /**
     * The pass token of a hand-off link, if the link is genuine, meant for this host, unexpired and unused;
     * the link is spent here.
     *
     * @param  array<string, mixed>  $query
     */
    public function redeem(string $host, array $query): ?string
    {
        $fields = [
            'token' => (string) ($query['token'] ?? ''),
            'expires' => (int) ($query['expires'] ?? 0),
            'nonce' => (string) ($query['nonce'] ?? ''),
            'next' => (string) ($query['next'] ?? '/'),
        ];

        if (! hash_equals($this->sign($host, $fields), (string) ($query['signature'] ?? ''))
            || $fields['expires'] < $this->clock->now()->getTimestamp()
            || strlen($fields['nonce']) !== 32
            // Single use: the nonce is remembered for longer than the link lives.
            || ! Cache::add('platform-pass-handoff:'.$fields['nonce'], true, 2 * (int) config('helpdesk.platform.pass_handoff_seconds'))) {
            return null;
        }

        return $this->check($fields['token']) !== null ? $fields['token'] : null;
    }

    /** The cookie that carries the pass on this host. */
    public function cookieFor(string $token): Cookie
    {
        // Host-only (no domain), HTTPS only, not readable by scripts, sent on top-level navigation. Built
        // directly: Laravel's cookie() would fill the domain from session.domain (.<platform domain>) and
        // send the pass to every host. EncryptCookies still encrypts it on the way out.
        return Cookie::create(self::cookieName(), $token, $this->clock->now()->addMinutes(self::minutes()), '/', null, true, true, false, Cookie::SAMESITE_LAX);
    }

    /** The admin holding a valid pass: the token is still held by the server and the admin still exists. */
    public function check(?string $token): ?PlatformUser
    {
        if ($token === null || $token === '') {
            return null;
        }
        $adminId = Cache::get(self::cacheKey($token));

        // A deactivated admin's passes stop working too (ADR-0025 §7).
        return is_string($adminId) ? PlatformUser::query()->active()->find($adminId) : null;
    }

    /** Ends every pass the console session handed out (console sign-out). */
    public function revokeFor(Session $console): void
    {
        foreach ((array) $console->get(self::SESSION_KEY, []) as $token) {
            Cache::forget(self::cacheKey((string) $token));
        }
        $console->forget(self::SESSION_KEY);
    }

    public static function cookieName(): string
    {
        return (string) config('helpdesk.platform.pass_cookie');
    }

    /** Only a path on the same host: no scheme, no `//host`, no backslash. */
    public static function safePath(?string $path): string
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\')
            ? $path
            : '/';
    }

    private static function minutes(): int
    {
        return (int) config('helpdesk.platform.pass_minutes');
    }

    private static function cacheKey(string $token): string
    {
        return 'platform-pass:'.hash('sha256', $token);
    }

    /**
     * @param  array<string, int|string>  $fields
     */
    private function sign(string $host, array $fields): string
    {
        $payload = implode('|', [$host, $fields['token'], $fields['expires'], $fields['nonce'], $fields['next']]);

        return hash_hmac('sha256', 'platform-pass|'.$payload, (string) config('app.key'));
    }
}
