<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\PlatformAccountController;
use App\Modules\Platform\Http\Controllers\PlatformAdminController;
use App\Modules\Platform\Http\Controllers\PlatformAuthController;
use App\Modules\Platform\Http\Controllers\PlatformDashboardController;
use App\Modules\Platform\Http\Controllers\PlatformPassController;
use App\Modules\Platform\Http\Controllers\PlatformSettingsController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Support\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// Platform API on the admin host (ADR-0021). Never runs inside a tenant.

// Starts the platform session and hands out the platform CSRF cookie.
Route::get('/csrf-cookie', fn () => response()->noContent())->name('platform.csrf-cookie');

Route::post('/auth/login', [PlatformAuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('platform.auth.login');

// Account recovery and invitations (ADR-0025 §7): no session needed; throttled.
Route::post('/auth/forgot-password', [PlatformAccountController::class, 'forgotPassword'])
    ->middleware('throttle:5,1')->name('platform.auth.forgot-password');
Route::post('/auth/reset-password', [PlatformAccountController::class, 'resetPassword'])
    ->middleware('throttle:10,1')->name('platform.auth.reset-password');
Route::get('/auth/invitations/{token}', [PlatformAccountController::class, 'invitation'])
    ->middleware('throttle:20,1')->where('token', '[A-Za-z0-9]{48}')->name('platform.auth.invitation');
Route::post('/auth/invitations/{token}/accept', [PlatformAccountController::class, 'acceptInvitation'])
    ->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{48}')->name('platform.auth.invitation.accept');

Route::middleware('auth:platform')->group(function (): void {
    Route::post('/auth/logout', [PlatformAuthController::class, 'logout'])->name('platform.auth.logout');
    Route::get('/me', [PlatformAuthController::class, 'me'])->name('platform.me');
    Route::patch('/me', [PlatformAccountController::class, 'updateProfile'])->name('platform.me.update');
    Route::put('/me/password', [PlatformAccountController::class, 'changePassword'])->middleware('throttle:10,1')->name('platform.me.password');

    Route::get('/dashboard', PlatformDashboardController::class)->name('platform.dashboard');
    Route::get('/settings', [PlatformSettingsController::class, 'show'])->name('platform.settings.show');
    Route::patch('/settings', [PlatformSettingsController::class, 'update'])->name('platform.settings.update');

    Route::get('/admins', [PlatformAdminController::class, 'index'])->name('platform.admins.index');
    Route::post('/admins', [PlatformAdminController::class, 'store'])->middleware('throttle:20,1')->name('platform.admins.store');
    Route::whereUuid('admin')->group(function (): void {
        Route::post('/admins/{admin}/resend-invitation', [PlatformAdminController::class, 'resend'])->middleware('throttle:10,1')->name('platform.admins.resend');
        Route::delete('/admins/{admin}/invitation', [PlatformAdminController::class, 'revoke'])->name('platform.admins.revoke');
        Route::post('/admins/{admin}/deactivate', [PlatformAdminController::class, 'deactivate'])->name('platform.admins.deactivate');
        Route::post('/admins/{admin}/reactivate', [PlatformAdminController::class, 'reactivate'])->name('platform.admins.reactivate');
    });

    Route::get('/tenants', [TenantController::class, 'index'])->name('platform.tenants.index');
    Route::post('/tenants', [TenantController::class, 'store'])->name('platform.tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('platform.tenants.show');
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->name('platform.tenants.update');
    Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('platform.tenants.suspend');
    Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('platform.tenants.reactivate');

    // A one-time link to the platform documentation or monitoring host (ADR-0024, M5-06).
    Route::post('/handoff', [PlatformPassController::class, 'handoff'])
        ->middleware('throttle:30,1')
        ->name('platform.handoff');

    // The tenant API reference for Platform Super Admins (docs/07-api/documentation.md §Access). The docs
    // host cannot serve them: the platform cookie is host-only on the admin host.
    Route::get('/docs', [ApiDocsController::class, 'ui'])->middleware('can:viewApiDocs')->name('platform.api-docs.ui');
    Route::get('/docs/openapi.json', [ApiDocsController::class, 'document'])->middleware('can:viewApiDocs')->name('platform.api-docs.document');
});
