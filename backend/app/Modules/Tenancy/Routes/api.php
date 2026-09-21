<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Workspace settings (docs/07-api/conventions.md §SLA and automation settings). What every user needs
// (branding, features) travels with GET /v1/me instead.
Route::middleware('can:settings.manage')->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/{section}', [SettingsController::class, 'show'])->where('section', '[a-z_.]+')->name('settings.show');
    Route::patch('/settings/{section}', [SettingsController::class, 'update'])->where('section', '[a-z_.]+')->name('settings.update');
});
