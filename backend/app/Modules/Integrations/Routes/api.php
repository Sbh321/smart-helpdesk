<?php

declare(strict_types=1);

use App\Modules\Integrations\Http\Controllers\ApiClientController;
use Illuminate\Support\Facades\Route;

// Settings → Developer → API clients (docs/07-api/authentication.md §3). SPA users only: these
// routes carry no `api-clients` marker, so no API client can manage clients, whatever its scopes.
Route::middleware('can:integrations.manage')->group(function (): void {
    Route::get('/api-clients', [ApiClientController::class, 'index'])->name('api-clients.index');
    Route::get('/api-clients/scopes', [ApiClientController::class, 'scopes'])->name('api-clients.scopes');
    Route::post('/api-clients', [ApiClientController::class, 'store'])->name('api-clients.store');
    Route::post('/api-clients/{apiClient}/revoke', [ApiClientController::class, 'revoke'])->whereUuid('apiClient')->name('api-clients.revoke');
});
