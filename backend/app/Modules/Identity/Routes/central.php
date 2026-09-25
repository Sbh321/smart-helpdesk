<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\WorkspaceReminderController;
use Illuminate\Support\Facades\Route;

// Pre-authentication routes that belong to no workspace (docs/07-api/authentication.md §Workspace finder).

Route::post('/auth/workspace-reminder', WorkspaceReminderController::class)
    ->middleware('throttle:workspace-reminder')
    ->name('auth.workspace-reminder');
