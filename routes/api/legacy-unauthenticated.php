<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AnimalCategoryController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\BatchTypeController;
use App\Http\Controllers\Api\BirthController;
use App\Http\Controllers\Api\BreedController;
use App\Http\Controllers\Api\CaravanController;
use App\Http\Controllers\Api\DeathController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\FieldMappingController;
use App\Http\Controllers\Api\ImportCaravansController;
use App\Http\Controllers\Api\ImportOCRGestationController;
use App\Http\Controllers\Api\ObtainCact01OrderController;
use App\Http\Controllers\Api\ObtainPar01OrderController;
use App\Http\Controllers\Api\PaternityController;
use App\Http\Controllers\Api\ProcessCact01Controller;
use App\Http\Controllers\Api\ProcessDest01Controller;
use App\Http\Controllers\Api\ProcessIng01Controller;
use App\Http\Controllers\Api\ProcessLser01Controller;
use App\Http\Controllers\Api\ProcessPar01Controller;
use App\Http\Controllers\Api\ProcessTor01Controller;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ResolveCact01SourceBatchController;
use App\Http\Controllers\Api\TagCalvesController;
use App\Http\Controllers\Api\WeighingOrderController;
use App\Http\Controllers\Api\WeighingTypeController;
use App\Http\Controllers\Api\WorkTemplateController;
use App\Http\Controllers\Api\WorkTemplateIdentifyController;
use Illuminate\Support\Facades\Route;

/*
 * SECURITY DEBT: these endpoints are declared outside `auth:sanctum` (see the NOTE in
 * CompanyContextMiddleware). Do not add routes here. Each one moves to its bounded-context
 * file once every client (SPA, mobile-scanner, ai-agent) is verified to send a token.
 */

// Herd: caravans, weights, gestation, births and paternity.
Route::post('/caravans/import', ImportCaravansController::class);
Route::post('/caravans/import-gestation-ocr', ImportOCRGestationController::class);

Route::controller(CaravanController::class)->prefix('caravans')->group(function (): void {
    Route::get('/summary', 'summary');
    Route::get('/', 'index');
    Route::post('/', 'upsert');
    Route::post('/bulk', 'bulkStore');
    Route::get('/movements', 'allMovements');
    Route::get('/{id}/movements', 'movements');
    Route::get('/{id}/pedigree', 'pedigree');
    Route::get('/{id}/weights', 'listWeights');
    Route::post('/{id}/weights', 'recordWeight');
    Route::post('/bulk-weights', 'bulkRecordWeights');
    Route::post('/bulk-birth', 'bulkBirth');
    Route::post('/{id}/gestation-loss', 'gestationLoss');
    Route::post('/bulk-gestation-diagnosis', 'bulkGestationDiagnosis');
    Route::post('/{id}/gestation-diagnosis', 'registerGestationDiagnosis');
    Route::post('/bulk-transfer', 'bulkTransfer');
    Route::post('/bulk-breeding-eligibility', 'bulkBreedingEligibility');
});

Route::get('/caravans/births-history', [BirthController::class, 'index']);
Route::post('/caravans/tag', [TagCalvesController::class, 'store']);
Route::post('/caravans/deaths', [DeathController::class, 'store']);

Route::get('/weighing-types', [WeighingTypeController::class, 'index']);
Route::patch('/weighing-types/{id}/active', [WeighingTypeController::class, 'toggle']);
Route::post('/weighing-orders/register-batch', [WeighingOrderController::class, 'registerBatch']);

Route::get('/paternity/pending', [PaternityController::class, 'pending']);
Route::post('/paternity/confirmations', [PaternityController::class, 'confirm']);

Route::get('/death-causes', [DeathController::class, 'causes']);
Route::get('/death-batches', [DeathController::class, 'deathBatches']);

Route::get('/field-mappings/{model}', [FieldMappingController::class, 'index']);
Route::post('/field-mappings/learn', [FieldMappingController::class, 'learn']);

// Batch hierarchy. `reserve` must be declared before the resource's `{batch}`.
Route::apiResource('providers', ProviderController::class)->only(['index', 'store', 'show', 'update']);
Route::apiResource('farms', FarmController::class)->only(['index', 'store', 'show']);

Route::get('/batches/reserve', [BatchController::class, 'reserve']);
Route::apiResource('batches', BatchController::class)->only(['index', 'store', 'show']);

Route::controller(BatchController::class)->prefix('batches')->group(function (): void {
    Route::get('/{id}/service-orders', 'serviceOrders')->whereNumber('id');
    Route::get('/{id}/overview', 'overview')->whereNumber('id');
    Route::post('/{id}/start-service', 'startService');
    Route::post('/assign-to-own', 'assignExternalToOwn');
    Route::patch('/{id}/activity', 'changeActivity');
    Route::patch('/{id}/management', 'changeManagement');
    Route::get('/{id}/weights', 'getWeightHistory');
});

Route::get('/batches/{batchId}/weighing-orders', [WeighingOrderController::class, 'byBatch'])->whereNumber('batchId');
Route::get('/batches/{id}/gestating-caravans', [CaravanController::class, 'gestatingByBatch']);
Route::get('/batches/{batchId}/untagged-calves', [TagCalvesController::class, 'index'])->whereNumber('batchId');

// Reference catalogs.
Route::get('/breeds', [BreedController::class, 'index']);

Route::controller(ActivityController::class)->prefix('activities')->group(function (): void {
    Route::get('/', 'index');
    Route::put('/config', 'updateConfig');
    Route::patch('/{id}/toggle', 'toggle');
});

Route::get('/batch-types', [BatchTypeController::class, 'index']);
Route::get('/animal-categories', [AnimalCategoryController::class, 'index']);
Route::get('/animal-categories/{id}/subcategories', [AnimalCategoryController::class, 'subcategories']);

// Work templates: identification, processing (with dry run) and order resolution.
Route::prefix('work-templates')->group(function (): void {
    Route::get('/', [WorkTemplateController::class, 'index']);
    Route::post('/identify', WorkTemplateIdentifyController::class);
    Route::post('/ing-01/process', ProcessIng01Controller::class)->middleware('dry.run');
    Route::post('/tor-01/process', ProcessTor01Controller::class)->middleware('dry.run');
    Route::post('/lser-01/process', ProcessLser01Controller::class)->middleware('dry.run');
    Route::post('/dest-01/process', ProcessDest01Controller::class)->middleware('dry.run');
    Route::post('/par-01/process', ProcessPar01Controller::class)->middleware('dry.run');
    Route::post('/par-01/order', ObtainPar01OrderController::class);
    Route::post('/cact-01/process', ProcessCact01Controller::class)->middleware('dry.run');
    Route::post('/cact-01/order', ObtainCact01OrderController::class);
    Route::post('/cact-01/source-batch', ResolveCact01SourceBatchController::class);
    Route::get('/{code}', [WorkTemplateController::class, 'show']);
});
