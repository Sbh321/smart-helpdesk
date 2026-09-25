<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

// The workspace's own subscription and payments (ADR-0025): owners and admins.
Route::middleware('can:billing.manage')->group(function (): void {
    Route::get('/billing', [BillingController::class, 'show'])->name('billing.show');
    Route::post('/billing/payments', [BillingController::class, 'store'])->middleware('throttle:10,1')->name('billing.payments.store');
});
