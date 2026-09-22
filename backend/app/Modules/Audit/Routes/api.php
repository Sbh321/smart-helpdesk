<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

// Settings → Audit (docs/04-domain/audit.md §Viewer). SPA users only: no API client scope reaches it.
Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit-logs.index');
