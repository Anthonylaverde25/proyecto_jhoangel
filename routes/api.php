<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\DocumentAnalysisController;
use App\Http\Controllers\Api\CaravanController;
use App\Http\Controllers\Api\FieldMappingController;
use App\Http\Controllers\Api\ImportCaravansController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\BatchController;
use Illuminate\Support\Facades\Route;

Route::post('/analyze-table', AnalysisController::class);
Route::match(['get', 'post'], '/test/azure-layout', DocumentAnalysisController::class);
Route::post('/caravans/import', ImportCaravansController::class);
Route::get('/caravans', [CaravanController::class, 'index']);
Route::post('/caravans/upsert', [CaravanController::class, 'upsert']);

Route::get('/field-mappings/{model}', [FieldMappingController::class, 'index']);
Route::post('/field-mappings/learn', [FieldMappingController::class, 'learn']);

// Jerarquía de Lotes
Route::apiResource('providers', ProviderController::class)->only(['index', 'store']);
Route::apiResource('farms', FarmController::class)->only(['index', 'store']);
Route::apiResource('batches', BatchController::class)->only(['index', 'store']);
