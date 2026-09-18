<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\PlatformAuthController;
use App\Modules\Platform\Http\Controllers\TenantController;
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
});
