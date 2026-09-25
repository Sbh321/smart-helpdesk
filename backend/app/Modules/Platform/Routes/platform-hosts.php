<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\PlatformPassController;
use Illuminate\Support\Facades\Route;

// The platform-only hosts, platform documentation and monitoring (ADR-0024): the proxy sends /_session/*
// here and asks /_session/check before serving anything else. No session and no tenant: the only state is
// the encrypted pass cookie. Loaded once per host by PlatformServiceProvider.

Route::get('/_session/start', [PlatformPassController::class, 'start'])->middleware('throttle:30,1');
Route::get('/_session/check', [PlatformPassController::class, 'check']);
