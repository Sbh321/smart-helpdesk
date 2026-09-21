<?php

declare(strict_types=1);

use App\Modules\Tickets\Http\Controllers\CategoryController;
use App\Modules\Tickets\Http\Controllers\TicketAttachmentController;
use App\Modules\Tickets\Http\Controllers\TicketBulkController;
use App\Modules\Tickets\Http\Controllers\TicketCommentController;
use App\Modules\Tickets\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// Tickets (docs/07-api/conventions.md §Endpoint inventory).
// `api-clients` opens a route to API clients (column C); `idempotent` honours Idempotency-Key.
// MVP-SHORTCUT: clients can read and create tickets but not yet edit, transition or comment,
// because those actions record a user actor; V1: M3-04 follow-up "client actors for ticket edits".

// Bulk routes first: `/tickets/{ticket}/…` would otherwise read "bulk" as a ticket id.
Route::post('/tickets/bulk/transition', [TicketBulkController::class, 'transition'])->middleware('can:tickets.update')->name('tickets.bulk.transition');
Route::get('/tickets', [TicketController::class, 'index'])->middleware(['can:tickets.view', 'api-clients'])->name('tickets.index');
Route::post('/tickets', [TicketController::class, 'store'])->middleware(['can:tickets.create', 'api-clients', 'idempotent'])->name('tickets.store');
Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->middleware(['can:tickets.view', 'api-clients'])->name('tickets.show');
Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->middleware('can:tickets.update')->name('tickets.update');
Route::post('/tickets/{ticket}/transition', [TicketController::class, 'transition'])->middleware('can:tickets.update')->name('tickets.transition');
Route::get('/tickets/{ticket}/history', [TicketController::class, 'history'])->middleware(['can:tickets.view', 'api-clients'])->name('tickets.history');
Route::get('/tickets/{ticket}/comments', [TicketCommentController::class, 'index'])->middleware(['can:tickets.view', 'api-clients'])->name('tickets.comments.index');
Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->middleware('can:tickets.update')->name('tickets.comments.store');
Route::get('/tickets/{ticket}/attachments', [TicketAttachmentController::class, 'index'])->middleware('can:tickets.view')->name('tickets.attachments.index');
Route::post('/tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])->middleware(['can:tickets.update', 'can:media.view'])->name('tickets.attachments.store');
Route::delete('/tickets/{ticket}/attachments/{media}', [TicketAttachmentController::class, 'destroy'])->middleware('can:tickets.update')->name('tickets.attachments.destroy');

Route::get('/categories', [CategoryController::class, 'index'])->middleware(['can:tickets.view', 'api-clients'])->name('categories.index');
Route::post('/categories', [CategoryController::class, 'store'])->middleware('can:settings.manage')->name('categories.store');
Route::patch('/categories/{category}', [CategoryController::class, 'update'])->middleware('can:settings.manage')->name('categories.update');
Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('can:settings.manage')->name('categories.destroy');
