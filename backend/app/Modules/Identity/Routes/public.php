<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Pre-authentication routes: the workspace comes from the request body (docs/07-api/authentication.md §1).

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

Route::post('/auth/invitations/{token}/accept', [AuthController::class, 'acceptInvitation'])
    ->middleware('throttle:login')
    ->name('auth.invitations.accept');

Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:login')
    ->name('auth.password.forgot');

Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:login')
    ->name('auth.password.reset');
