<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BullHealthEvaluationController;
use App\Http\Controllers\Api\SampleShipmentController;
use App\Http\Controllers\Api\VeterinaryPortalController;
use Illuminate\Support\Facades\Route;

// Loaded under the `veterinary-portal` prefix with the portal middleware (see routes/api.php).

Route::get('/pathogens', [BullHealthEvaluationController::class, 'getPathogens']);

Route::controller(VeterinaryPortalController::class)->group(function (): void {
    Route::get('/session', 'session');
    Route::get('/workspace', 'workspace');
    Route::post('/evaluations', 'storeEvaluation');

    // Professional's inbox: acts to sign and signed ones waiting for the lab.
    Route::get('/acts', 'pendingActs');
    Route::get('/acts/{id}', 'showAct')->whereNumber('id');
    Route::post('/acts/{id}/sign', 'signAct')->whereNumber('id');
    Route::patch('/acts/{id}/destination-plan', 'updateDestinationPlan')->whereNumber('id');
    Route::post('/acts/{id}/lab-report', 'storeLabReport')->whereNumber('id');
});

// ADR-30 / ADR-36: the shipment is the unit, and it does not hang from an act.
Route::controller(SampleShipmentController::class)->group(function (): void {
    Route::get('/pending-tubes', 'pendingTubes');
    Route::get('/shipments', 'index');
    Route::post('/shipments', 'store');
    Route::patch('/shipments/{id}', 'update')->whereNumber('id');
    Route::post('/shipments/{id}/void', 'void')->whereNumber('id');
    Route::get('/institutions/suggestions', 'institutionSuggestions');
});
