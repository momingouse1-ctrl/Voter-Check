<?php

use App\Http\Controllers\GeographyController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TransliterationController;
use App\Http\Controllers\ExcelImportController;
use Illuminate\Support\Facades\Route;

// Session / Email gate
Route::post('/session/email', [SessionController::class, 'store']);
Route::get('/users/emails', [SessionController::class, 'index']);

// Stats
Route::get('/stats', [StatsController::class, 'index']);

// ─── Geography Filter APIs (public) ─────────────────────────────────────────
Route::prefix('filters')->group(function () {
    Route::get('/districts',        [GeographyController::class, 'districts']);
    Route::get('/cities',           [GeographyController::class, 'cities']);
    Route::get('/assemblies',       [GeographyController::class, 'assemblies']);
    Route::get('/polling-stations', [GeographyController::class, 'pollingStations']);
});

// ─── Admin Geography CRUD ────────────────────────────────────────────────────
Route::prefix('admin')->group(function () {
    Route::post('/districts',        [GeographyController::class, 'storeDistrict']);
    Route::put('/districts/{id}',    [GeographyController::class, 'updateDistrict']);
    Route::post('/cities',           [GeographyController::class, 'storeCity']);
    Route::put('/cities/{id}',       [GeographyController::class, 'updateCity']);
    Route::post('/assemblies',       [GeographyController::class, 'storeAssembly']);
    Route::post('/polling-stations', [GeographyController::class, 'storePollingStation']);
    Route::post('/upload-excel',     [ExcelImportController::class, 'import']);
    Route::get('/import-history',    [ExcelImportController::class, 'history']);
});

// ─── PDF Management ──────────────────────────────────────────────────────────
Route::prefix('pdfs')->group(function () {
    Route::get('/',                 [PdfController::class, 'index']);
    Route::post('/upload',          [PdfController::class, 'upload']);
    Route::get('/{id}',             [PdfController::class, 'show']);
    Route::delete('/{id}',          [PdfController::class, 'destroy']);
    Route::post('/{id}/reprocess',  [PdfController::class, 'reprocess']);
    Route::get('/{id}/file',        [PdfController::class, 'serve']);
    Route::get('/{id}/download',    [PdfController::class, 'download']);
});

// ─── Search ──────────────────────────────────────────────────────────────────
Route::post('/search',              [SearchController::class, 'search']);
Route::get('/search/recent',        [SearchController::class, 'recent']);
Route::post('/search/transliterate',[TransliterationController::class, 'transliterate']);

// ─── Settings ────────────────────────────────────────────────────────────────
Route::get('/settings',                 [SettingsController::class, 'index']);
Route::put('/settings',                 [SettingsController::class, 'update']);
Route::post('/settings/clear-index',    [SettingsController::class, 'clearIndex']);
Route::post('/settings/reprocess-all',  [SettingsController::class, 'reprocessAll']);
Route::post('/settings/process-queue',  [SettingsController::class, 'processQueue']);
