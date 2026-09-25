<?php

declare(strict_types=1);

use App\Modules\Platform\Models\PlatformUser;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Testing\TestResponse;

/*
 * The platform documentation behind the platform sign-in (ADR-0024, roadmap M5-06): a one-time,
 * one-minute hand-off link from the console, exchanged on the platform-docs host for a host-only pass
 * that the proxy checks on every request.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-25 10:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->admin = PlatformUser::query()->create([
        'name' => 'Platform admin',
        'email' => 'docs-admin@platform.test',
        'password' => 'platform-password',
    ]);
    $this->cookieName = (string) config('helpdesk.platform.docs_cookie');
});

function platformDocsUrl(string $path): string
{
    return 'https://'.config('helpdesk.hosts.platform_docs').$path;
}

function consoleDocsUrl(string $next = '/'): string
{
    return 'https://'.config('helpdesk.hosts.admin').'/platform/docs?'.http_build_query(['next' => $next]);
}

/** Asks the console API for a hand-off link as the given admin. */
function handoffLink(PlatformUser $admin, string $next = '/'): string
{
    test()->actingAs($admin, 'platform');
    $url = (string) test()->postJson('https://'.config('helpdesk.hosts.admin').'/platform-api/docs/handoff', ['next' => $next])
        ->assertOk()
        ->json('data.url');

    return $url;
}

/** Follows a hand-off link on the platform-docs host. */
function redeem(string $url): TestResponse
{
    return test()->get($url);
}

it('hands a signed-in admin over to the platform docs, which then lets every request through', function (): void {
    $link = handoffLink($this->admin, '/03-architecture/overview');

    expect($link)->toStartWith(platformDocsUrl('/_session/start?'));

    $response = redeem($link)->assertRedirect('/03-architecture/overview');
    $cookie = $response->getCookie($this->cookieName, decrypt: false);
    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');

    $pass = $response->getCookie($this->cookieName)->getValue();
    $this->withCookie($this->cookieName, $pass)
        ->get(platformDocsUrl('/_session/check'))
        ->assertNoContent();
});

it('accepts each hand-off link once', function (): void {
    $link = handoffLink($this->admin);

    redeem($link)->assertRedirect('/');
    redeem($link)->assertRedirect(consoleDocsUrl('/'));
});

it('refuses a link older than a minute or one that was altered', function (): void {
    $link = handoffLink($this->admin);
    $this->clock->set('2026-09-25 10:01:01');
    redeem($link)->assertRedirect(consoleDocsUrl('/'))->assertCookieMissing($this->cookieName);

    $this->clock->set('2026-09-25 10:00:00');
    $altered = str_replace('next=%2F', 'next=%2Fadr%2F', handoffLink($this->admin));
    redeem($altered)->assertRedirect(consoleDocsUrl('/'))->assertCookieMissing($this->cookieName);
});

it('sends a visitor without a pass to the console, back to the page they asked for', function (): void {
    $this->get(platformDocsUrl('/_session/check'), ['X-Forwarded-Uri' => '/adr/'])
        ->assertRedirect(consoleDocsUrl('/adr/'));

    $this->get(platformDocsUrl('/_session/check'), ['X-Forwarded-Uri' => '//evil.test/'])
        ->assertRedirect(consoleDocsUrl('/'));
});

it('ends the pass when it expires or the admin no longer exists', function (): void {
    $pass = redeem(handoffLink($this->admin))->getCookie($this->cookieName)->getValue();

    $this->clock->set('2026-09-25 18:00:01'); // eight hours and a second later
    $this->withCookie($this->cookieName, $pass)->get(platformDocsUrl('/_session/check'))->assertRedirect();

    $this->clock->set('2026-09-25 10:00:00');
    $this->withCookie($this->cookieName, $pass)->get(platformDocsUrl('/_session/check'))->assertNoContent();
    $this->admin->delete();
    $this->withCookie($this->cookieName, $pass)->get(platformDocsUrl('/_session/check'))->assertRedirect();
});

it('does not accept a pass written by hand, without the application key', function (): void {
    $this->withUnencryptedCookie($this->cookieName, json_encode(['admin' => $this->admin->id, 'expires' => PHP_INT_MAX]))
        ->get(platformDocsUrl('/_session/check'))
        ->assertRedirect();
});

it('gives the hand-off only to a signed-in platform admin', function (): void {
    $this->postJson('https://'.config('helpdesk.hosts.admin').'/platform-api/docs/handoff')->assertUnauthorized();

    // A workspace user's session is a different guard and a different cookie.
    $acme = createTenant('acme');
    actingAsTenantUser($acme);
    $this->postJson('https://'.config('helpdesk.hosts.admin').'/platform-api/docs/handoff')->assertUnauthorized();
});

it('keeps the page to open on the platform-docs host', function (): void {
    redeem(handoffLink($this->admin, 'https://evil.test/'))->assertRedirect('/');
});
