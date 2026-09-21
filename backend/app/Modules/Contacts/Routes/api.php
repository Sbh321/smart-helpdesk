<?php

declare(strict_types=1);

use App\Modules\Contacts\Http\Controllers\ContactController;
use App\Modules\Contacts\Http\Controllers\OrganizationController;
use App\Modules\Contacts\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

// Contacts, organisations and tags (docs/07-api/conventions.md §Endpoint inventory).
// `api-clients` opens a route to API clients (column C of the inventory).

Route::get('/contacts', [ContactController::class, 'index'])->middleware(['can:contacts.view', 'api-clients'])->name('contacts.index');
Route::get('/contacts/typeahead', [ContactController::class, 'typeahead'])->middleware(['can:contacts.view', 'api-clients'])->name('contacts.typeahead');
Route::post('/contacts', [ContactController::class, 'store'])->middleware(['can:contacts.manage', 'api-clients', 'idempotent'])->name('contacts.store');
Route::get('/contacts/{contact}', [ContactController::class, 'show'])->middleware(['can:contacts.view', 'api-clients'])->name('contacts.show');
Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->middleware(['can:contacts.manage', 'api-clients'])->name('contacts.update');
Route::post('/contacts/{contact}/archive', [ContactController::class, 'archive'])->middleware('can:contacts.manage')->name('contacts.archive');
Route::post('/contacts/{contact}/unarchive', [ContactController::class, 'unarchive'])->middleware('can:contacts.manage')->name('contacts.unarchive');

Route::get('/organizations', [OrganizationController::class, 'index'])->middleware(['can:contacts.view', 'api-clients'])->name('organizations.index');
Route::post('/organizations', [OrganizationController::class, 'store'])->middleware(['can:contacts.manage', 'api-clients'])->name('organizations.store');
Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->middleware(['can:contacts.view', 'api-clients'])->name('organizations.show');
Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->middleware(['can:contacts.manage', 'api-clients'])->name('organizations.update');

Route::get('/tags', [TagController::class, 'index'])->middleware(['can:tickets.view', 'api-clients'])->name('tags.index');
Route::post('/tags', [TagController::class, 'store'])->middleware('can:tickets.update')->name('tags.store');
Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->middleware('can:settings.manage')->name('tags.destroy');
