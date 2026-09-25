<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\SignupController;
use Illuminate\Support\Facades\Route;

// Self sign-up (ADR-0025 §8): pre-authentication, outside any workspace.
Route::get('/signup/address', [SignupController::class, 'address'])->middleware('throttle:30,1')->name('signup.address');
Route::post('/signup', [SignupController::class, 'store'])->middleware('throttle:signup')->name('signup.store');
Route::post('/signup/verify', [SignupController::class, 'verify'])->middleware('throttle:10,1')->name('signup.verify');
