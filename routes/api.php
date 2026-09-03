<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\BreedController;
use App\Http\Controllers\Api\CaravanController;
use App\Http\Controllers\Api\FieldMappingController;
use App\Http\Controllers\Api\ImportCaravansController;
use App\Http\Controllers\Api\ImportOCRGestationController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\WorkTemplateController;
use App\Http\Controllers\Api\WorkTemplateIdentifyController;
use App\Http\Controllers\Api\ProcessIng01Controller;
use App\Http\Controllers\Api\ProcessTor01Controller;
use App\Http\Controllers\Api\ProcessLabResultsController;
use App\Http\Controllers\Api\BatchTypeController;
use App\Http\Controllers\Api\ServiceOrderController;
use App\Http\Controllers\Api\BirthController;
use App\Http\Controllers\Api\AnimalCategoryController;
use Illuminate\Support\Facades\Route;

use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);

    Route::post('/caravans/import', ImportCaravansController::class);
    Route::post('/caravans/import-gestation-ocr', ImportOCRGestationController::class);
    Route::get('/caravans', [CaravanController::class, 'index']);
    Route::post('/caravans', [CaravanController::class, 'upsert']);
    Route::post('/caravans/bulk', [CaravanController::class, 'bulkStore']);
    Route::get('/caravans/movements', [CaravanController::class, 'allMovements']);
    Route::get('/caravans/{id}/movements', [CaravanController::class, 'movements']);
    Route::get('/caravans/{id}/pedigree', [CaravanController::class, 'pedigree']);
    Route::get('/caravans/{id}/weights', [CaravanController::class, 'listWeights']);
    Route::post('/caravans/{id}/weights', [CaravanController::class, 'recordWeight']);
    Route::post('/caravans/bulk-weights', [CaravanController::class, 'bulkRecordWeights']);
    Route::post('/caravans/bulk-birth', [CaravanController::class, 'bulkBirth']);
    Route::post('/caravans/{id}/gestation-loss', [CaravanController::class, 'gestationLoss']);
    Route::post('/caravans/bulk-gestation-diagnosis', [CaravanController::class, 'bulkGestationDiagnosis']);
    Route::post('/caravans/{id}/gestation-diagnosis', [CaravanController::class, 'registerGestationDiagnosis']);
    Route::patch('/caravans/{id}/wean', [CaravanController::class, 'wean']);
    Route::post('/caravans/bulk-wean', [CaravanController::class, 'bulkWean']);
    Route::post('/caravans/bulk-transfer', [CaravanController::class, 'bulkTransfer']);
    Route::get('/caravans/births-history', [BirthController::class, 'index']);
    Route::get('/caravans/pending-sires', [BirthController::class, 'pendingSires']);
    Route::patch('/caravans/{calfId}/assign-sire', [BirthController::class, 'assignSire']);

    Route::get('/field-mappings/{model}', [FieldMappingController::class, 'index']);
    Route::post('/field-mappings/learn', [FieldMappingController::class, 'learn']);

    // Jerarquía de Lotes
    Route::apiResource('providers', ProviderController::class)->only(['index', 'store', 'show']);
    Route::apiResource('farms', FarmController::class)->only(['index', 'store', 'show']);
    Route::get('/batches/reserve', [BatchController::class, 'reserve']);
    Route::post('/batches/service', [BatchController::class, 'storeService']);
    Route::post('/batches/assign-to-own', [BatchController::class, 'assignExternalToOwn']);
    Route::apiResource('batches', BatchController::class)->only(['index', 'store', 'show']);
    Route::patch('/batches/{id}/activity', [BatchController::class, 'changeActivity']);
    Route::get('/batches/{id}/weights', [BatchController::class, 'getWeightHistory']);
    Route::get('/batches/{id}/gestating-caravans', [CaravanController::class, 'gestatingByBatch']);


    Route::get('/breeds', [BreedController::class, 'index']);
    Route::get('/activities', [ActivityController::class, 'index']);
    Route::patch('/activities/{id}/toggle', [ActivityController::class, 'toggle']);
    Route::get('/batch-types', [BatchTypeController::class, 'index']);
    Route::get('/animal-categories', [AnimalCategoryController::class, 'index']);
    Route::get('/animal-categories/{id}/subcategories', [AnimalCategoryController::class, 'subcategories']);

    // Gestión de Plantillas
    Route::get('/work-templates', [WorkTemplateController::class, 'index']);
    Route::post('/work-templates/identify', WorkTemplateIdentifyController::class);
    Route::post('/work-templates/ing-01/process', ProcessIng01Controller::class);
    Route::post('/work-templates/tor-01/process', ProcessTor01Controller::class);
    Route::get('/work-templates/{code}', [WorkTemplateController::class, 'show']);

    // Órdenes de Servicio
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/service-orders', [ServiceOrderController::class, 'index']);
        Route::post('/service-orders', [ServiceOrderController::class, 'store']);
        Route::get('/service-orders/{id}', [ServiceOrderController::class, 'show']);
        Route::post('/service-orders/{id}/approve', [ServiceOrderController::class, 'approve']);
        Route::post('/service-orders/{id}/complete', [ServiceOrderController::class, 'complete']);
        Route::patch('/service-orders/{id}/status', [ServiceOrderController::class, 'updateStatus']);
        Route::post('/service-orders/{id}/upload-pdf', [ServiceOrderController::class, 'uploadPdf']);

        // Pre-Servicio, Salud del Toro & Diagnósticos Veterinarios
        Route::get('/pre-service/bulls', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'getBulls']);
        Route::get('/pre-service/bulls/{caravanId}/clinical-history', [\App\Http\Controllers\Api\BullClinicalHistoryController::class, '__invoke']);
        Route::get('/pathogens', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'getPathogens']);
        Route::post('/pre-service/bull-evaluations', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'registerBullEvaluation']);
        Route::post('/pre-service/lab-results', [\App\Http\Controllers\Api\ProcessLabResultsController::class, '__invoke']);
        Route::post('/caravans/{caravanId}/diagnoses', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'createDiagnosis']);
        Route::patch('/diagnoses/{id}/resolve', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'resolveDiagnosis']);
    });
});
