<?php

declare(strict_types=1);

use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Support\PlatformPass;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;

/*
 * The platform-only hosts behind the platform sign-in (ADR-0024, roadmap M5-06): a one-time, one-minute
 * hand-off link from the console, exchanged on the platform documentation or monitoring host for a
 * host-only pass that the proxy checks on every request and that console sign-out ends.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-25 10:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->admin = PlatformUser::query()->create([
        'name' => 'Platform admin',
        'email' => 'pass-admin@platform.test',
        'password' => 'platform-password',
    ]);
    $this->cookieName = PlatformPass::cookieName();
});

function onHost(string $target, string $path): string
{
    return 'https://'.PlatformPass::host($target).$path;
}

function consoleUrl(string $page, string $next = '/'): string
{
    return 'https://'.config('helpdesk.hosts.admin').'/platform/'.$page.'?'.http_build_query(['next' => $next]);
}

/** Asks the console API for a hand-off link as the given admin. */
function handoffLink(PlatformUser $admin, string $target = 'docs', string $next = '/'): string
{
    test()->actingAs($admin, 'platform');

    return (string) test()->postJson('https://'.config('helpdesk.hosts.admin').'/platform-api/handoff', ['target' => $target, 'next' => $next])
        ->assertOk()
        ->json('data.url');
}

function followLink(string $url): TestResponse
{
    return test()->get($url);
}

it('hands a signed-in admin over to the platform docs, which then lets every request through', function (): void {
    $link = handoffLink($this->admin, 'docs', '/03-architecture/overview');
    expect($link)->toStartWith(onHost('docs', '/_session/start?'));

    $response = followLink($link)->assertRedirect('/03-architecture/overview');
    $cookie = $response->getCookie($this->cookieName, decrypt: false);
    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');

    $pass = $response->getCookie($this->cookieName)->getValue();
    $this->withCookie($this->cookieName, $pass)->get(onHost('docs', '/_session/check'))->assertNoContent();
});

it('hands over to the monitoring host too, and a link for one host does not work on the other', function (): void {
    $link = handoffLink($this->admin, 'monitor', '/horizon');
    expect($link)->toStartWith(onHost('monitor', '/_session/start?'));

    $moved = str_replace(PlatformPass::host('monitor'), PlatformPass::host('docs'), $link);
    followLink($moved)->assertRedirect(consoleUrl('docs'))->assertCookieMissing($this->cookieName);

    $pass = followLink($link)->assertRedirect('/horizon')->getCookie($this->cookieName)->getValue();
    $this->withCookie($this->cookieName, $pass)->get(onHost('monitor', '/_session/check'))->assertNoContent();
});

it('accepts each hand-off link once', function (): void {
    $link = handoffLink($this->admin);

    followLink($link)->assertRedirect('/');
    followLink($link)->assertRedirect(consoleUrl('docs'));
});

it('refuses a link older than a minute or one that was altered', function (): void {
    $link = handoffLink($this->admin);
    $this->clock->set('2026-09-25 10:01:01');
    followLink($link)->assertRedirect(consoleUrl('docs'))->assertCookieMissing($this->cookieName);

    $this->clock->set('2026-09-25 10:00:00');
    $altered = str_replace('next=%2F', 'next=%2Fadr%2F', handoffLink($this->admin));
    followLink($altered)->assertRedirect(consoleUrl('docs'))->assertCookieMissing($this->cookieName);
});

it('sends a visitor without a pass to the console page of that host, back to the page they asked for', function (): void {
    $this->get(onHost('docs', '/_session/check'), ['X-Forwarded-Uri' => '/adr/'])->assertRedirect(consoleUrl('docs', '/adr/'));
    $this->get(onHost('monitor', '/_session/check'), ['X-Forwarded-Uri' => '/horizon'])->assertRedirect(consoleUrl('monitor', '/horizon'));
    $this->get(onHost('docs', '/_session/check'), ['X-Forwarded-Uri' => '//evil.test/'])->assertRedirect(consoleUrl('docs'));
});

it('ends the pass when the admin signs out of the console', function (): void {
    // Signed in through the real endpoint, so sign-out runs as in the console.
    $admin = 'https://'.config('helpdesk.hosts.admin').'/platform-api';
    $this->postJson("{$admin}/auth/login", ['email' => 'pass-admin@platform.test', 'password' => 'platform-password'])->assertOk();
    $link = (string) $this->postJson("{$admin}/handoff", ['target' => 'docs'])->assertOk()->json('data.url');
    $pass = followLink($link)->getCookie($this->cookieName)->getValue();
    $this->withCookie($this->cookieName, $pass)->get(onHost('docs', '/_session/check'))->assertNoContent();

    $this->postJson('https://'.config('helpdesk.hosts.admin').'/platform-api/auth/logout')->assertNoContent();

    $this->withCookie($this->cookieName, $pass)->get(onHost('docs', '/_session/check'))->assertRedirect();
});

it('ends the pass when it expires or the admin no longer exists', function (): void {
    $pass = followLink(handoffLink($this->admin))->getCookie($this->cookieName)->getValue();
    $this->admin->delete();

    $this->withCookie($this->cookieName, $pass)->get(onHost('docs', '/_session/check'))->assertRedirect();
});

it('does not accept a pass written by hand, without the application key', function (): void {
    $this->withUnencryptedCookie($this->cookieName, 'guessed-token')->get(onHost('docs', '/_session/check'))->assertRedirect();
});

it('gives the hand-off only to a signed-in platform admin', function (): void {
    $this->postJson('https://'.config('helpdesk.hosts.admin').'/platform-api/handoff')->assertUnauthorized();

    // A workspace user's session is a different guard and a different cookie.
    actingAsTenantUser(createTenant('acme'));
    $this->postJson('https://'.config('helpdesk.hosts.admin').'/platform-api/handoff')->assertUnauthorized();
});

it('keeps the page to open on the platform host', function (): void {
    followLink(handoffLink($this->admin, 'docs', 'https://evil.test/'))->assertRedirect('/');
});

it('shows the health dashboard on the monitoring host only with a pass', function (): void {
    $this->get(onHost('monitor', '/health'))->assertForbidden();

    $pass = followLink(handoffLink($this->admin, 'monitor', '/health'))->getCookie($this->cookieName)->getValue();
    $this->withCookie($this->cookieName, $pass)->get(onHost('monitor', '/health'))->assertOk();
});

it('lets Horizon in only with a valid pass', function (): void {
    expect(Gate::check('viewHorizon'))->toBeFalse();

    $pass = followLink(handoffLink($this->admin, 'monitor'))->getCookie($this->cookieName)->getValue();
    $this->withCookie($this->cookieName, $pass)->get(onHost('monitor', '/_session/check'))->assertNoContent();
    request()->cookies->set($this->cookieName, $pass);

    expect(Gate::check('viewHorizon'))->toBeTrue();
});

it('shows the monitoring start page with links to its tools, only with a pass', function (): void {
    $this->get(onHost('monitor', '/'))->assertForbidden();

    $pass = followLink(handoffLink($this->admin, 'monitor'))->getCookie($this->cookieName)->getValue();
    $this->withCookie($this->cookieName, $pass)->get(onHost('monitor', '/'))
        ->assertOk()
        ->assertSee('Monitoring')
        ->assertSee('href="/horizon"', false)
        ->assertSee('href="/health"', false)
        ->assertSee('href="/storage"', false);
});
