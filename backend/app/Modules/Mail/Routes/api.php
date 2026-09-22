<?php

declare(strict_types=1);

use App\Modules\Mail\Http\Controllers\EmailSettingsController;
use App\Modules\Mail\Http\Controllers\InboundEmailController;
use Illuminate\Support\Facades\Route;

// Settings → Email (docs/07-api/conventions.md, M3-18). Registered before the generic
// /settings/{section} routes of Tenancy (MailServiceProvider boots first), so these win for `email`.
Route::middleware('can:mail.manage')->group(function (): void {
    Route::get('/settings/email', [EmailSettingsController::class, 'show'])->name('settings.email.show');
    Route::patch('/settings/email', [EmailSettingsController::class, 'update'])->name('settings.email.update');

    // Inbound log (M3-19). SPA users only: no API client scope reaches it.
    Route::get('/inbound-emails', [InboundEmailController::class, 'index'])->name('inbound-emails.index');
    Route::get('/inbound-emails/{inboundEmail}', [InboundEmailController::class, 'show'])->whereUuid('inboundEmail')->name('inbound-emails.show');
});
