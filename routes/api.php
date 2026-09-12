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
use App\Http\Controllers\Api\DiagnosticProtocolController;
use App\Http\Controllers\Api\SampleShipmentController;
use App\Http\Controllers\Api\ProtocolAttachmentController;
use App\Http\Controllers\Api\VeterinarianBatchAssignmentController;
use App\Http\Controllers\Api\VeterinarianController;
use App\Http\Controllers\Api\VeterinaryPortalController;
use App\Http\Controllers\Api\VeterinaryPortalTokenController;
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

        // Planilla de manga: una jornada completa emite un acta de extracción (ADR-11 / ADR-13).
        Route::post('/pre-service/evaluation-sheets', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'registerEvaluationSheet']);
        Route::post('/pre-service/lab-results', [\App\Http\Controllers\Api\ProcessLabResultsController::class, '__invoke']);
        Route::post('/caravans/{caravanId}/diagnoses', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'createDiagnosis']);
        Route::patch('/diagnoses/{id}/resolve', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'resolveDiagnosis']);

        // Catálogo de profesionales matriculados. Las instituciones no se registran (ADR-29).

        // ADR-33: dar de alta un profesional incluye invitarlo. Es un permiso distinto del alta.
        Route::post('/veterinarians/{id}/invite', [\App\Http\Controllers\Api\VeterinarianInvitationController::class, 'store'])->whereNumber('id');

        Route::get('/veterinarians', [VeterinarianController::class, 'index']);
        Route::post('/veterinarians', [VeterinarianController::class, 'store']);
        Route::patch('/veterinarians/{id}', [VeterinarianController::class, 'update']);

        // Asignación profesional ↔ tropa (habilita lo que el portal deja ver)
        Route::get('/veterinarian-assignments', [VeterinarianBatchAssignmentController::class, 'index']);
        Route::post('/veterinarian-assignments', [VeterinarianBatchAssignmentController::class, 'store']);
        Route::delete('/veterinarian-assignments/{id}', [VeterinarianBatchAssignmentController::class, 'destroy']);

        // Caso de Uso 2 — Digitalización asistida de evidencia externa (WhatsApp / PDF)
        Route::get('/diagnostic-protocols', [DiagnosticProtocolController::class, 'index']);
        Route::post('/diagnostic-protocols', [DiagnosticProtocolController::class, 'store']);
        // §11.6: despachadas hace rato y todavía en silencio. Filtro consultable, sin alerta.
        Route::get('/diagnostic-protocols/shipped-without-report', [DiagnosticProtocolController::class, 'shippedWithoutReport']);
        Route::get('/diagnostic-protocols/{id}', [DiagnosticProtocolController::class, 'show'])->whereNumber('id');
        Route::post('/diagnostic-protocols/{id}/void', [DiagnosticProtocolController::class, 'void']);

        // Accesos temporales al portal para veterinarios y centros de salud externos
        // One row per professional: whose portal exists, what waits in it, who holds a key.
        Route::get('/veterinary-portal-directory', [VeterinaryPortalTokenController::class, 'directory']);
        Route::get('/veterinary-portal-tokens', [VeterinaryPortalTokenController::class, 'index']);
        Route::post('/veterinary-portal-tokens', [VeterinaryPortalTokenController::class, 'store']);
        Route::post('/veterinary-portal-tokens/{id}/reissue', [VeterinaryPortalTokenController::class, 'reissue'])->whereNumber('id');
        Route::delete('/veterinary-portal-tokens/{id}', [VeterinaryPortalTokenController::class, 'destroy']);
    });

    /*
     * Lectura de evidencia sanitaria (ADR-6). Fuera del grupo `auth:sanctum` porque la URL es
     * firmada y de vida corta, pero el controlador vuelve a exigir pertenencia a la compañía:
     * una URL firmada filtrada no debe alcanzar para leer prueba documental.
     */
    /*
     * ADR-33: públicas a propósito, como los enlaces del portal. La invitación ES la credencial
     * y el servidor la valida en cada llamada.
     */
    Route::get('/invitations/{token}', [\App\Http\Controllers\Api\VeterinarianInvitationController::class, 'show']);
    Route::post('/invitations/{token}/accept', [\App\Http\Controllers\Api\VeterinarianInvitationController::class, 'accept']);

    Route::get('/protocol-attachments/{attachment}/download', ProtocolAttachmentController::class)
        ->middleware('signed')
        ->name('protocol-attachments.download');

    /*
     * Caso de Uso 1 — Portal Veterinario.
     *
     * Un único grupo sirve los dos accesos: el profesional interno (usuario con rol
     * `veterinarian` en `company_user`) y el externo que llega por URL con token temporal
     * (`X-Vet-Access-Token`). El middleware resuelve ambos a IVeterinaryPortalContext.
     */
    Route::middleware(['veterinary.portal', 'veterinary.portal.readonly'])->prefix('veterinary-portal')->group(function () {
        Route::get('/session', [VeterinaryPortalController::class, 'session']);
        Route::get('/workspace', [VeterinaryPortalController::class, 'workspace']);
        Route::get('/pathogens', [\App\Http\Controllers\Api\BullHealthEvaluationController::class, 'getPathogens']);
        Route::post('/evaluations', [VeterinaryPortalController::class, 'storeEvaluation']);

        // Bandeja del profesional: sus actas por firmar y las firmadas que esperan laboratorio.
        Route::get('/acts', [VeterinaryPortalController::class, 'pendingActs']);

        // ADR-30 / ADR-36: el envío es la unidad, y no cuelga de un acta.
        Route::get('/pending-tubes', [SampleShipmentController::class, 'pendingTubes']);
        Route::get('/shipments', [SampleShipmentController::class, 'index']);
        Route::post('/shipments', [SampleShipmentController::class, 'store']);
        Route::patch('/shipments/{id}', [SampleShipmentController::class, 'update'])->whereNumber('id');
        Route::post('/shipments/{id}/void', [SampleShipmentController::class, 'void'])->whereNumber('id');
        Route::get('/institutions/suggestions', [SampleShipmentController::class, 'institutionSuggestions']);
        Route::get('/acts/{id}', [VeterinaryPortalController::class, 'showAct'])->whereNumber('id');
        Route::post('/acts/{id}/sign', [VeterinaryPortalController::class, 'signAct'])->whereNumber('id');
        Route::patch('/acts/{id}/destination-plan', [VeterinaryPortalController::class, 'updateDestinationPlan'])->whereNumber('id');
        Route::post('/acts/{id}/lab-report', [VeterinaryPortalController::class, 'storeLabReport'])->whereNumber('id');
    });
});
