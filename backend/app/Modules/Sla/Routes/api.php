<?php

declare(strict_types=1);

use App\Modules\Sla\Http\Controllers\CalendarController;
use App\Modules\Sla\Http\Controllers\CalendarHolidayController;
use App\Modules\Sla\Http\Controllers\SlaPolicyController;
use App\Modules\Sla\Http\Controllers\TicketSlaController;
use Illuminate\Support\Facades\Route;

Route::get('/calendars', [CalendarController::class, 'index'])->middleware('can:tickets.view');
Route::post('/calendars', [CalendarController::class, 'store'])->middleware('can:calendars.manage');
Route::get('/calendars/{calendar}', [CalendarController::class, 'show'])->middleware('can:tickets.view');
Route::patch('/calendars/{calendar}', [CalendarController::class, 'update'])->middleware('can:calendars.manage');
Route::delete('/calendars/{calendar}', [CalendarController::class, 'destroy'])->middleware('can:calendars.manage');
Route::post('/calendars/{calendar}/holidays', [CalendarHolidayController::class, 'store'])->middleware('can:calendars.manage');
Route::delete('/calendars/{calendar}/holidays/{holiday}', [CalendarHolidayController::class, 'destroy'])->middleware('can:calendars.manage');

Route::get('/sla-policies', [SlaPolicyController::class, 'index'])->middleware('can:tickets.view');
Route::post('/sla-policies', [SlaPolicyController::class, 'store'])->middleware('can:sla.manage');
Route::get('/sla-policies/{policy}', [SlaPolicyController::class, 'show'])->middleware('can:tickets.view');
Route::patch('/sla-policies/{policy}', [SlaPolicyController::class, 'update'])->middleware('can:sla.manage');
Route::delete('/sla-policies/{policy}', [SlaPolicyController::class, 'destroy'])->middleware('can:sla.manage');

Route::get('/tickets/{ticket}/sla', [TicketSlaController::class, 'show'])->middleware('can:tickets.view');
