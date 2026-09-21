<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\HistoryController;
use App\Modules\Reporting\Http\Controllers\OverviewController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Change logs and as-of views (docs/07-api/conventions.md §Reports). The route needs tickets.view, which
// every reader of any history holds; the controller applies the per-subject rule (HistorySubjects).
Route::middleware('can:tickets.view')->whereUuid('id')->group(function (): void {
    Route::get('/history/{type}/{id}', [HistoryController::class, 'index'])->where('type', '[a-z_]+')->name('history.index');
    Route::get('/history/{type}/{id}/as-of', [HistoryController::class, 'asOf'])->where('type', '[a-z_]+')->name('history.as-of');
});

// The report catalogue (docs/07-api/conventions.md §Reports); each report adds its own permissions.
Route::middleware('can:reports.view')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->where('report', 'rpt-[a-z0-9]+')->name('reports.show');
    Route::post('/reports/{report}/run', [ReportController::class, 'run'])->where('report', 'rpt-[a-z0-9]+')->name('reports.run');
    Route::get('/reports/{report}/records', [ReportController::class, 'records'])->where('report', 'rpt-[a-z0-9]+')->name('reports.records');
});

// Entity 360 overviews: the entity's own view permission.
Route::whereUuid('id')->group(function (): void {
    Route::get('/tickets/{id}/overview', [OverviewController::class, 'ticket'])->middleware('can:tickets.view')->name('tickets.overview');
    Route::get('/contacts/{id}/overview', [OverviewController::class, 'contact'])->middleware('can:contacts.view')->name('contacts.overview');
    Route::get('/organizations/{id}/overview', [OverviewController::class, 'organization'])->middleware('can:contacts.view')->name('organizations.overview');
    Route::get('/agents/{id}/overview', [OverviewController::class, 'agent'])->middleware('can:agents.view')->name('agents.overview');
    Route::get('/teams/{id}/overview', [OverviewController::class, 'team'])->middleware('can:agents.view')->name('teams.overview');
    Route::get('/categories/{id}/overview', [OverviewController::class, 'category'])->middleware('can:tickets.view')->name('categories.overview');
});
