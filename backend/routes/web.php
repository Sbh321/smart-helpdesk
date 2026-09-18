<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The application has no server-rendered pages; the SPA is served by the proxy.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'api' => '/v1']));
