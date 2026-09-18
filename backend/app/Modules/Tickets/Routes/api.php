<?php

declare(strict_types=1);

use App\Modules\Tickets\Http\Controllers\CategoryController;
use App\Modules\Tickets\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// Tickets (docs/07-api/conventions.md §Endpoint inventory). Lifecycle actions arrive in M2.

Route::get('/tickets', [TicketController::class, 'index'])->middleware('can:tickets.view')->name('tickets.index');
Route::post('/tickets', [TicketController::class, 'store'])->middleware('can:tickets.create')->name('tickets.store');
Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->middleware('can:tickets.view')->name('tickets.show');
Route::get('/tickets/{ticket}/history', [TicketController::class, 'history'])->middleware('can:tickets.view')->name('tickets.history');

Route::get('/categories', [CategoryController::class, 'index'])->middleware('can:tickets.view')->name('categories.index');
