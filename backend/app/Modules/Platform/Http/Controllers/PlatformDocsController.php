<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Support\PlatformDocsPass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The platform documentation behind the platform sign-in (ADR-0024, roadmap M5-06).
 */
final class PlatformDocsController
{
    public function __construct(private readonly PlatformDocsPass $pass) {}

    /**
     * Platform docs hand-off.
     *
     * A one-time link, valid for a minute, that signs the current platform admin in to the platform
     * documentation host. `next` is the page to open there.
     */
    public function handoff(Request $request): JsonResponse
    {
        /** @var PlatformUser $admin */
        $admin = $request->user('platform');

        return new JsonResponse(['data' => [
            'url' => $this->pass->handoffUrl($admin, PlatformDocsPass::safePath($request->string('next')->toString() ?: '/')),
        ]]);
    }

    /** On the platform-docs host: exchanges the hand-off link for the docs pass cookie. */
    public function start(Request $request): RedirectResponse
    {
        $admin = $this->pass->redeem($request->query());
        if ($admin === null) {
            return $this->toConsole('/');
        }

        return redirect()->to(PlatformDocsPass::safePath($request->query('next')))
            ->withCookie($this->pass->cookieFor($admin));
    }

    /**
     * The proxy's `forward_auth` target on the platform-docs host: 204 lets the request through; anything
     * else is returned to the browser, here a redirect to the console, which hands off again after sign-in.
     */
    public function check(Request $request): Response|RedirectResponse
    {
        if ($this->pass->check($request->cookie((string) config('helpdesk.platform.docs_cookie'))) !== null) {
            return response()->noContent();
        }

        return $this->toConsole(PlatformDocsPass::safePath($request->header('X-Forwarded-Uri')));
    }

    private function toConsole(string $next): RedirectResponse
    {
        return redirect()->away(sprintf(
            'https://%s/platform/docs?%s',
            config('helpdesk.hosts.admin'),
            http_build_query(['next' => $next]),
        ));
    }
}
