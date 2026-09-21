<?php

declare(strict_types=1);

use App\Modules\Integrations\Http\Controllers\ApiClientController;
use App\Modules\Integrations\Http\Controllers\WebhookController;
use App\Modules\Integrations\Http\Controllers\WebhookDeliveryController;
use Illuminate\Support\Facades\Route;

// Settings → Developer → API clients (docs/07-api/authentication.md §3). SPA users only: these
// routes carry no `api-clients` marker, so no API client can manage clients, whatever its scopes.
Route::middleware('can:integrations.manage')->group(function (): void {
    Route::get('/api-clients', [ApiClientController::class, 'index'])->name('api-clients.index');
    Route::get('/api-clients/scopes', [ApiClientController::class, 'scopes'])->name('api-clients.scopes');
    Route::post('/api-clients', [ApiClientController::class, 'store'])->name('api-clients.store');
    Route::post('/api-clients/{apiClient}/revoke', [ApiClientController::class, 'revoke'])->whereUuid('apiClient')->name('api-clients.revoke');
});

// Settings → Developer → Webhooks (docs/07-api/webhooks.md). Open to API clients: the
// `webhooks:manage` scope maps to `integrations.manage`, and these are the only routes it reaches.
Route::middleware(['can:integrations.manage', 'api-clients'])->group(function (): void {
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::get('/webhooks/events', [WebhookController::class, 'events'])->name('webhooks.events');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->whereUuid('webhook')->name('webhooks.show');
    Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->whereUuid('webhook')->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->whereUuid('webhook')->name('webhooks.destroy');
    Route::post('/webhooks/{webhook}/enable', [WebhookController::class, 'enable'])->whereUuid('webhook')->name('webhooks.enable');
    Route::post('/webhooks/{webhook}/disable', [WebhookController::class, 'disable'])->whereUuid('webhook')->name('webhooks.disable');
    Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->whereUuid('webhook')->name('webhooks.rotate-secret');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->whereUuid('webhook')->middleware('throttle:webhook-test')->name('webhooks.test');
    Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->whereUuid('webhook')->name('webhooks.deliveries');
    Route::get('/webhook-deliveries/{delivery}', [WebhookDeliveryController::class, 'show'])->whereUuid('delivery')->name('webhook-deliveries.show');
    Route::post('/webhook-deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry'])->whereUuid('delivery')->name('webhook-deliveries.retry');
});
