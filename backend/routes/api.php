<?php

declare(strict_types=1);

use App\Support\Http\Controllers\HealthController;
use App\Support\Http\Middleware\RequireHealthToken;
use App\Support\Http\Resources\PingResource;
use Illuminate\Support\Facades\Route;

// System routes. Module routes are loaded from app/Modules/*/Routes/api.php by their providers.

Route::get('/ping',
    /**
     * Ping.
     *
     * Liveness check for clients: answers without authentication and without touching the database.
     *
     * @unauthenticated
     */
    fn (): PingResource => new PingResource,
)->name('system.ping');

Route::get('/health', HealthController::class)
    ->middleware([RequireHealthToken::class, 'throttle:30,1'])
    ->name('system.health');
