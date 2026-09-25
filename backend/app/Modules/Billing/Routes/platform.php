<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\PlatformPaymentController;
use App\Modules\Billing\Http\Controllers\PlatformPlanController;
use App\Modules\Billing\Http\Controllers\PlatformSubscriptionController;
use Illuminate\Support\Facades\Route;

// Billing for platform super admins on the admin host (ADR-0025). Never runs inside a tenant.
Route::middleware('auth:platform')->group(function (): void {
    Route::get('/plans', [PlatformPlanController::class, 'index'])->name('platform.plans.index');
    Route::post('/plans', [PlatformPlanController::class, 'store'])->name('platform.plans.store');
    Route::patch('/plans/{plan}', [PlatformPlanController::class, 'update'])->whereUuid('plan')->name('platform.plans.update');

    Route::get('/tenants/{tenant}/subscription', [PlatformSubscriptionController::class, 'show'])->name('platform.subscriptions.show');
    Route::put('/tenants/{tenant}/subscription', [PlatformSubscriptionController::class, 'update'])->name('platform.subscriptions.update');
    Route::post('/tenants/{tenant}/payments', [PlatformPaymentController::class, 'store'])->name('platform.payments.store');

    Route::get('/payments', [PlatformPaymentController::class, 'index'])->name('platform.payments.index');
    Route::whereUuid('payment')->group(function (): void {
        Route::get('/payments/{payment}', [PlatformPaymentController::class, 'show'])->name('platform.payments.show');
        Route::get('/payments/{payment}/receipt', [PlatformPaymentController::class, 'receipt'])->name('platform.payments.receipt');
        Route::post('/payments/{payment}/approve', [PlatformPaymentController::class, 'approve'])->name('platform.payments.approve');
        Route::post('/payments/{payment}/reject', [PlatformPaymentController::class, 'reject'])->name('platform.payments.reject');
    });
});
