<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Support\PlatformPass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The platform documentation and monitoring hosts behind the platform sign-in (ADR-0024, roadmap M5-06).
 */
final class PlatformPassController
{
    public function __construct(private readonly PlatformPass $pass) {}

    /**
     * Platform host hand-off.
     *
     * A one-time link, valid for a minute, that signs the current platform admin in to the platform
     * documentation (`target=docs`) or monitoring (`target=monitor`) host. `next` is the page to open there.
     */
    public function handoff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['sometimes', 'string', Rule::in(array_keys(PlatformPass::TARGETS))],
            'next' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        /** @var PlatformUser $admin */
        $admin = $request->user('platform');

        return new JsonResponse(['data' => [
            'url' => $this->pass->handoffUrl($admin, $data['target'] ?? 'docs', PlatformPass::safePath($data['next'] ?? '/'), $request->session()),
        ]]);
    }

    /** On a platform host: exchanges the hand-off link for the pass cookie. */
    public function start(Request $request): RedirectResponse
    {
        $token = $this->pass->redeem($request->getHost(), $request->query());
        if ($token === null) {
            return $this->toConsole($request, '/');
        }

        return redirect()->to(PlatformPass::safePath($request->query('next')))
            ->withCookie($this->pass->cookieFor($token));
    }

    /**
     * The proxy's `forward_auth` target on a platform host: 204 lets the request through; anything else is
     * returned to the browser, here a redirect to the console, which hands off again after sign-in.
     */
    public function check(Request $request): Response|RedirectResponse
    {
        if ($this->pass->check($request->cookie(PlatformPass::cookieName())) !== null) {
            return response()->noContent();
        }

        return $this->toConsole($request, PlatformPass::safePath($request->header('X-Forwarded-Uri')));
    }

    private function toConsole(Request $request, string $next): RedirectResponse
    {
        $page = $request->getHost() === PlatformPass::host('monitor') ? 'monitor' : 'docs';

        return redirect()->away(sprintf(
            'https://%s/platform/%s?%s',
            config('helpdesk.hosts.admin'),
            $page,
            http_build_query(['next' => $next]),
        ));
    }
}
