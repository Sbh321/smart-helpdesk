<?php

declare(strict_types=1);

use App\Modules\Media\Http\Controllers\MediaFolderController;
use App\Modules\Media\Http\Controllers\MediaLibraryController;
use App\Modules\Media\Http\Controllers\MediaManagementController;
use App\Modules\Media\Http\Controllers\MediaUploadController;
use Illuminate\Support\Facades\Route;

Route::post('/media/intent', [MediaUploadController::class, 'intent'])->middleware(['can:media.upload', 'throttle:media-intent'])->name('media.intent');
Route::get('/media/folders', [MediaFolderController::class, 'index'])->middleware('can:media.view')->name('media.folders.index');
Route::post('/media/folders', [MediaFolderController::class, 'store'])->middleware('can:media.manage')->name('media.folders.store');
Route::patch('/media/folders/{folder}', [MediaFolderController::class, 'update'])->middleware('can:media.manage')->whereUuid('folder')->name('media.folders.update');
Route::delete('/media/folders/{folder}', [MediaFolderController::class, 'destroy'])->middleware('can:media.manage')->whereUuid('folder')->name('media.folders.destroy');
Route::get('/media', [MediaLibraryController::class, 'index'])->middleware('can:media.view')->name('media.index');
Route::get('/media/usage', [MediaLibraryController::class, 'usage'])->middleware('can:media.view')->name('media.usage');

Route::whereUuid('media')->group(function (): void {
    Route::post('/media/{media}/complete', [MediaUploadController::class, 'complete'])->middleware('can:media.upload')->name('media.complete');
    Route::get('/media/{media}', [MediaLibraryController::class, 'show'])->middleware('can:media.view')->name('media.show');
    Route::get('/media/{media}/download', [MediaLibraryController::class, 'download'])->middleware('can:media.view')->name('media.download');
    Route::get('/media/{media}/open', [MediaLibraryController::class, 'open'])->middleware('can:media.view')->name('media.open');
    Route::get('/media/{media}/variants/{name}', [MediaLibraryController::class, 'variant'])->middleware('can:media.view')->whereIn('name', ['thumb', 'preview'])->name('media.variant');
    Route::patch('/media/{media}', [MediaManagementController::class, 'update'])->middleware('can:media.manage')->name('media.update');
    Route::post('/media/{media}/trash', [MediaManagementController::class, 'trash'])->middleware('can:media.manage')->name('media.trash');
    Route::post('/media/{media}/restore', [MediaManagementController::class, 'restore'])->middleware('can:media.manage')->name('media.restore');
    Route::delete('/media/{media}', [MediaManagementController::class, 'destroy'])->middleware('can:media.manage')->name('media.destroy');
});
