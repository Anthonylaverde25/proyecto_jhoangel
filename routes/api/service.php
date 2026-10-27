<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BullClinicalHistoryController;
use App\Http\Controllers\Api\BullHealthEvaluationController;
use App\Http\Controllers\Api\ProcessLabResultsController;
use App\Http\Controllers\Api\SanitaryExposureAlertController;
use App\Http\Controllers\Api\ServiceOrderController;
use App\Http\Controllers\Api\ServiceOrderInseminationController;
use Illuminate\Support\Facades\Route;

// Service orders (natural service and AI).
Route::controller(ServiceOrderController::class)->prefix('service-orders')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{id}', 'show');
    Route::post('/{id}/approve', 'approve');
    Route::post('/{id}/close-diagnosis', 'closeDiagnosis')->whereNumber('id');
    Route::get('/{id}/diagnosis', 'diagnosis')->whereNumber('id');
    Route::post('/{id}/not-diagnosed', 'markNotDiagnosed')->whereNumber('id');
    Route::post('/{id}/incidents/{incidentId}/resolve', 'resolveIncident')->whereNumber(['id', 'incidentId']);
    Route::patch('/{id}/status', 'updateStatus');
    Route::post('/{id}/upload-pdf', 'uploadPdf');
    Route::post('/{id}/replace-bull', 'replaceBull')->whereNumber('id');
    Route::post('/{id}/retire-bull', 'retireBull')->whereNumber('id');
    Route::get('/{id}/bull-replacements', 'getBullReplacements')->whereNumber('id');
    Route::get('/{id}/bull-history', 'bullHistory')->whereNumber('id');
    Route::post('/{id}/close-service', 'closeService')->whereNumber('id');
});

// AI service orders (semen S-B): inseminations, end of service, repaso bulls.
Route::controller(ServiceOrderInseminationController::class)->prefix('service-orders/{id}')->group(function (): void {
    Route::get('/inseminations', 'index')->whereNumber('id');
    Route::post('/inseminations', 'store')->whereNumber('id');
    Route::post('/inseminations/{inseminationId}/void', 'void')->whereNumber(['id', 'inseminationId']);
    Route::post('/end-service', 'endService')->whereNumber('id');
    Route::post('/repaso-bulls', 'addRepasoBulls')->whereNumber('id');
});

// Females that served with a bull found positive to a contagious pathogen.
Route::controller(SanitaryExposureAlertController::class)->prefix('sanitary-exposure-alerts')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/open-for-females', 'openForFemales');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::post('/{id}/review-females', 'reviewFemales')->whereNumber('id');
    Route::post('/{id}/resolve', 'resolve')->whereNumber('id');
});

// Pre-service, bull health and veterinary diagnoses.
Route::controller(BullHealthEvaluationController::class)->group(function (): void {
    Route::get('/pre-service/bulls', 'getBulls');
    Route::get('/pathogens', 'getPathogens');
    Route::post('/pre-service/bull-evaluations', 'registerBullEvaluation');

    // Chute planilla: a full working day issues an extraction act (ADR-11 / ADR-13).
    Route::post('/pre-service/evaluation-sheets', 'registerEvaluationSheet');
    Route::post('/caravans/{caravanId}/diagnoses', 'createDiagnosis');
    Route::patch('/diagnoses/{id}/resolve', 'resolveDiagnosis');
});

Route::get('/pre-service/bulls/{caravanId}/clinical-history', BullClinicalHistoryController::class);
Route::post('/pre-service/lab-results', ProcessLabResultsController::class);
