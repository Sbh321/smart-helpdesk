<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\PlatformAuthController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Support\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// Platform API on the admin host (ADR-0021). Never runs inside a tenant.

// Starts the platform session and hands out the platform CSRF cookie.
Route::get('/csrf-cookie', fn () => response()->noContent())->name('platform.csrf-cookie');

Route::post('/auth/login', [PlatformAuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('platform.auth.login');

Route::middleware('auth:platform')->group(function (): void {
    Route::post('/auth/logout', [PlatformAuthController::class, 'logout'])->name('platform.auth.logout');
    Route::get('/me', [PlatformAuthController::class, 'me'])->name('platform.me');

    Route::get('/tenants', [TenantController::class, 'index'])->name('platform.tenants.index');
    Route::post('/tenants', [TenantController::class, 'store'])->name('platform.tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('platform.tenants.show');
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->name('platform.tenants.update');
    Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('platform.tenants.suspend');
    Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('platform.tenants.reactivate');

    // The tenant API reference for Platform Super Admins (docs/07-api/documentation.md §Access). The docs
    // host cannot serve them: the platform cookie is host-only on the admin host.
    Route::get('/docs', [ApiDocsController::class, 'ui'])->middleware('can:viewApiDocs')->name('platform.api-docs.ui');
    Route::get('/docs/openapi.json', [ApiDocsController::class, 'document'])->middleware('can:viewApiDocs')->name('platform.api-docs.document');
});
