<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
Route::get('/me', [MeController::class, 'show'])->name('me.show');
Route::patch('/me/preferences', [MeController::class, 'updatePreferences'])->name('me.preferences.update');

// Roles and the permission catalogue (ADR-0007). Code checks permissions, never role names.
Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('can:roles.manage')->name('permissions.index');
Route::get('/roles', [RoleController::class, 'index'])->middleware('can:roles.manage')->name('roles.index');
Route::post('/roles', [RoleController::class, 'store'])->middleware('can:roles.manage')->name('roles.store');
Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->name('roles.update');
Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->name('roles.destroy');
