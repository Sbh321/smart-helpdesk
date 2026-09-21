<?php

declare(strict_types=1);

use App\Modules\Automation\Http\Controllers\PriorityPreviewController;
use App\Modules\Automation\Http\Controllers\TicketAssignmentController;
use App\Modules\Automation\Http\Controllers\TicketBulkAssignmentController;
use App\Modules\Automation\Http\Controllers\TicketDuplicateController;
use App\Modules\Automation\Http\Controllers\TicketPriorityController;
use Illuminate\Support\Facades\Route;

// Before `/tickets/{ticket}/…`, which would read "bulk" as a ticket id.
Route::post('/tickets/bulk/assign', [TicketBulkAssignmentController::class, 'assign'])->middleware('can:tickets.assign')->name('tickets.bulk.assign');
Route::post('/tickets/{ticket}/priority', [TicketPriorityController::class, 'store'])
    ->middleware('can:tickets.update')->name('tickets.priority');
Route::post('/settings/automation/priority/preview', [PriorityPreviewController::class, 'store'])
    ->middleware('can:settings.manage')->name('settings.priority.preview');
Route::get('/tickets/{ticket}/assignment-candidates', [TicketAssignmentController::class, 'candidates'])
    ->middleware('can:tickets.assign')->name('tickets.assignment-candidates');
Route::post('/tickets/{ticket}/assign', [TicketAssignmentController::class, 'assign'])
    ->middleware('can:tickets.assign')->name('tickets.assign');
Route::post('/tickets/{ticket}/auto-assign', [TicketAssignmentController::class, 'autoAssign'])
    ->middleware('can:tickets.assign')->name('tickets.auto-assign');
Route::post('/tickets/{ticket}/unassign', [TicketAssignmentController::class, 'unassign'])
    ->middleware('can:tickets.assign')->name('tickets.unassign');

// Duplicate suggestions (docs/05-algorithms/duplicate-detection.md). `preview-duplicates` is a fixed
// segment, so it is registered by this provider before it can be mistaken for a ticket id.
Route::post('/tickets/preview-duplicates', [TicketDuplicateController::class, 'preview'])
    ->middleware(['can:tickets.create', 'throttle:duplicate-preview'])->name('tickets.duplicates.preview');
Route::get('/tickets/{ticket}/duplicates', [TicketDuplicateController::class, 'index'])
    ->middleware('can:tickets.view')->name('tickets.duplicates.index');
Route::post('/tickets/{ticket}/mark-duplicate', [TicketDuplicateController::class, 'mark'])
    ->middleware(['can:tickets.update', 'can:tickets.close'])->name('tickets.duplicates.mark');
Route::post('/tickets/{ticket}/duplicates/{candidate}/dismiss', [TicketDuplicateController::class, 'dismiss'])
    ->middleware('can:tickets.update')->whereUuid('candidate')->name('tickets.duplicates.dismiss');
