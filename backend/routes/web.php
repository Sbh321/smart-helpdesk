<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Middleware\EnsureTenantActive;
use App\Modules\Tenancy\Http\Middleware\EnsureTenantMembership;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantFromPrincipal;
use App\Support\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// The application has no server-rendered pages; the SPA is served by the proxy.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'api' => '/v1']));

// API reference on the docs host (Caddy maps `/` and `/openapi.json` here). Workspace users with
// `integrations.manage` only: the workspace session cookie is sent to every host of the platform domain,
// so the tenant resolves exactly as on the API (docs/07-api/documentation.md §Access). Platform Super
// Admins read the same document on the admin host (Platform/Routes/platform.php).
Route::middleware([
    ResolveTenantFromPrincipal::class,
    EnsureTenantActive::class,
    'auth:web',
    EnsureTenantMembership::class,
    'can:viewApiDocs',
])->group(function (): void {
    Route::get('/docs/api', [ApiDocsController::class, 'ui'])->name('api-docs.ui');
    Route::get('/docs/api.json', [ApiDocsController::class, 'document'])->name('api-docs.document');
});
