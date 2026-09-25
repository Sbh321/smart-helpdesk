<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\PlatformDocsController;
use Illuminate\Support\Facades\Route;

// The platform-docs host (ADR-0024): the proxy sends /_session/* here and asks /_session/check before
// serving any page. No session and no tenant: the only state is the encrypted docs pass cookie.

Route::get('/_session/start', [PlatformDocsController::class, 'start'])
    ->middleware('throttle:30,1')
    ->name('platform-docs.start');

Route::get('/_session/check', [PlatformDocsController::class, 'check'])->name('platform-docs.check');
