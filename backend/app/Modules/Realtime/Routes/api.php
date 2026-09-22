<?php

declare(strict_types=1);

use App\Modules\Realtime\Http\Controllers\ChannelAuthorizationController;
use Illuminate\Support\Facades\Route;

// Channel authorisation for the SPA's WebSocket (docs/03-architecture/realtime.md). Per-channel
// permissions are checked in Realtime\Support\Channels, so the route itself carries no `can:`.
Route::post('/broadcasting/auth', ChannelAuthorizationController::class)
    ->middleware('throttle:60,1')
    ->name('realtime.auth');
