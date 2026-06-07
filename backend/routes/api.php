<?php

use App\Http\Controllers\PdfController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

// Session / Email gate
Route::post('/session/email', [SessionController::class, 'store']);
Route::get('/users/emails', [SessionController::class, 'index']);

// Stats
Route::get('/stats', [StatsController::class, 'index']);

// PDF Management
Route::prefix('pdfs')->group(function () {
    Route::get('/',                 [PdfController::class, 'index']);
    Route::post('/upload',          [PdfController::class, 'upload']);
    Route::get('/{id}',             [PdfController::class, 'show']);
    Route::delete('/{id}',          [PdfController::class, 'destroy']);
    Route::post('/{id}/reprocess',  [PdfController::class, 'reprocess']);
    Route::get('/{id}/file',        [PdfController::class, 'serve']);
    Route::get('/{id}/download',    [PdfController::class, 'download']);
});

// Search
Route::post('/search',          [SearchController::class, 'search']);
Route::get('/search/recent',    [SearchController::class, 'recent']);

// Settings
Route::get('/settings',             [SettingsController::class, 'index']);
Route::put('/settings',             [SettingsController::class, 'update']);
Route::post('/settings/clear-index', [SettingsController::class, 'clearIndex']);
Route::post('/settings/reprocess-all', [SettingsController::class, 'reprocessAll']);
Route::post('/settings/process-queue', [SettingsController::class, 'processQueue']);
