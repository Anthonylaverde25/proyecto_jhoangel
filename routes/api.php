<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\BreedController;
use App\Http\Controllers\Api\DocumentAnalysisController;
use App\Http\Controllers\Api\CaravanController;
use App\Http\Controllers\Api\FieldMappingController;
use App\Http\Controllers\Api\ImportCaravansController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);

    Route::post('/analyze-table', AnalysisController::class);
    Route::match(['get', 'post'], '/test/azure-layout', DocumentAnalysisController::class);
    Route::post('/caravans/import', ImportCaravansController::class);
    Route::get('/caravans', [CaravanController::class, 'index']);
    Route::post('/caravans/upsert', [CaravanController::class, 'upsert']);
    Route::get('/caravans/movements', [CaravanController::class, 'allMovements']);
    Route::get('/caravans/{id}/movements', [CaravanController::class, 'movements']);

    Route::get('/field-mappings/{model}', [FieldMappingController::class, 'index']);
    Route::post('/field-mappings/learn', [FieldMappingController::class, 'learn']);

    // Jerarquía de Lotes
    Route::apiResource('providers', ProviderController::class)->only(['index', 'store']);
    Route::apiResource('farms', FarmController::class)->only(['index', 'store']);
    Route::apiResource('batches', BatchController::class)->only(['index', 'store']);

    Route::get('/breeds', [BreedController::class, 'index']);
});
