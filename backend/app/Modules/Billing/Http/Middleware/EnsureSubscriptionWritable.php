<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Middleware;

use App\Modules\Billing\Support\BillingException;
use App\Modules\Billing\Support\Subscriptions;
use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A workspace whose subscription has expired (after its grace period) is read-only (ADR-0025 §6):
 * every write on the tenant API is refused with 403 `workspace_read_only`, for people and API clients
 * alike, except what it takes to leave, to read and export, and to pay.
 */
final readonly class EnsureSubscriptionWritable
{
    /** Writes that stay open in a read-only workspace, by route name. */
    public const ALLOWED = [
        'auth.logout',
        'me.preferences.update',
        'notifications.read',
        'notifications.read-all',
        // Reading and exporting are reads, even when they are POSTs.
        'reports.run',
        'reports.exports.store',
        'exports.tickets',
        // Paying: the receipt upload and the payment itself.
        'media.intent',
        'media.complete',
        'billing.payments.store',
    ];

    public function __construct(private Subscriptions $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        $tenantId = tenant()?->getKey();
        if ($tenantId !== null && $this->subscriptions->statusOf((string) $tenantId)->readOnly()) {
            throw new BillingException(
                ErrorCode::WorkspaceReadOnly,
                'This workspace is read-only because its subscription has ended. An owner or admin can renew it under Settings, Billing.',
            );
        }

        return $next($request);
    }
}
