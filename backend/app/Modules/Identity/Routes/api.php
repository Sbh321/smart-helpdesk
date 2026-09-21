<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
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

// Settings → Users (docs/07-api/conventions.md §Users).
Route::middleware('can:users.manage')->group(function (): void {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/invitations', [UserController::class, 'invite'])->middleware('throttle:user-invitations')->name('users.invite');
    Route::whereUuid('user')->group(function (): void {
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/invitation', [UserController::class, 'resendInvitation'])->middleware('throttle:user-invitations')->name('users.invitation.resend');
        Route::post('/users/{user}/disable', [UserController::class, 'disable'])->name('users.disable');
        Route::post('/users/{user}/enable', [UserController::class, 'enable'])->name('users.enable');
    });
});
