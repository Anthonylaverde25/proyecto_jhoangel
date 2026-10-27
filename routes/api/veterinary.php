<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DiagnosticProtocolController;
use App\Http\Controllers\Api\VeterinarianBatchAssignmentController;
use App\Http\Controllers\Api\VeterinarianController;
use App\Http\Controllers\Api\VeterinarianInvitationController;
use App\Http\Controllers\Api\VeterinaryPortalTokenController;
use Illuminate\Support\Facades\Route;

// Catalog of licensed professionals. Institutions are not registered (ADR-29).
// ADR-33: registering a professional includes inviting them. It is a permission distinct from registration.
Route::post('/veterinarians/{id}/invite', [VeterinarianInvitationController::class, 'store'])->whereNumber('id');

Route::controller(VeterinarianController::class)->prefix('veterinarians')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::patch('/{id}', 'update');
});

// Professional ↔ batch assignment (enables what the portal lets them see).
Route::controller(VeterinarianBatchAssignmentController::class)->prefix('veterinarian-assignments')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::delete('/{id}', 'destroy');
});

// Use Case 2 — Assisted digitization of external evidence (WhatsApp / PDF).
Route::controller(DiagnosticProtocolController::class)->prefix('diagnostic-protocols')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    // §11.6: shipped a while ago and still silent. A queryable filter, not an alert.
    Route::get('/shipped-without-report', 'shippedWithoutReport');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::post('/{id}/void', 'void');
});

// Temporary portal accesses for external veterinarians and health centers.
// One row per professional: whose portal exists, what waits in it, who holds a key.
// Array syntax: a bare 'directory' resolves to PHP's built-in Directory class inside a controller group.
Route::get('/veterinary-portal-directory', [VeterinaryPortalTokenController::class, 'directory']);

Route::controller(VeterinaryPortalTokenController::class)->group(function (): void {
    Route::get('/veterinary-portal-tokens', 'index');
    Route::post('/veterinary-portal-tokens', 'store');
    Route::post('/veterinary-portal-tokens/{id}/reissue', 'reissue')->whereNumber('id');
    Route::delete('/veterinary-portal-tokens/{id}', 'destroy');
});
