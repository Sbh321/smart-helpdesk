<?php

declare(strict_types=1);

use App\Modules\Agents\Http\Controllers\AgentController;
use App\Modules\Agents\Http\Controllers\AgentShiftController;
use App\Modules\Agents\Http\Controllers\SkillController;
use App\Modules\Agents\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/agents', [AgentController::class, 'index'])->middleware('can:agents.view')->name('agents.index');
Route::get('/agents/available-users', [AgentController::class, 'availableUsers'])->middleware('can:agents.manage')->name('agents.available-users');
Route::post('/agents', [AgentController::class, 'store'])->middleware('can:agents.manage')->name('agents.store');
Route::get('/agents/{agent}', [AgentController::class, 'show'])->middleware('can:agents.view')->name('agents.show');
Route::patch('/agents/{agent}', [AgentController::class, 'update'])->middleware('can:agents.view')->name('agents.update');
Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])->middleware('can:agents.manage')->name('agents.destroy');
Route::get('/agents/{agent}/workload', [AgentController::class, 'workload'])->middleware('can:agents.view')->name('agents.workload');
Route::get('/agents/{agent}/shifts', [AgentShiftController::class, 'index'])->middleware('can:agents.view')->name('agents.shifts.index');
Route::put('/agents/{agent}/shifts', [AgentShiftController::class, 'update'])->middleware('can:shifts.manage')->name('agents.shifts.update');

Route::get('/skills', [SkillController::class, 'index'])->middleware('can:agents.view')->name('skills.index');
Route::post('/skills', [SkillController::class, 'store'])->middleware('can:agents.manage')->name('skills.store');
Route::patch('/skills/{skill}', [SkillController::class, 'update'])->middleware('can:agents.manage')->name('skills.update');
Route::delete('/skills/{skill}', [SkillController::class, 'destroy'])->middleware('can:agents.manage')->name('skills.destroy');

Route::get('/teams', [TeamController::class, 'index'])->middleware('can:agents.view')->name('teams.index');
Route::post('/teams', [TeamController::class, 'store'])->middleware('can:teams.manage')->name('teams.store');
Route::patch('/teams/{team}', [TeamController::class, 'update'])->middleware('can:teams.manage')->name('teams.update');
Route::put('/teams/{team}/members', [TeamController::class, 'replaceMembers'])->middleware('can:teams.manage')->name('teams.members.update');
Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->middleware('can:teams.manage')->name('teams.destroy');
