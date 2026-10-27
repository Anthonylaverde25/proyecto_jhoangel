<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use Illuminate\Support\Facades\Route;

// Authenticated user and active company.
Route::get('/me', [AuthController::class, 'me']);

// SEC-02: revoke the current token server-side (the client also clears its local copy).
Route::post('/logout', [AuthController::class, 'logout']);

// The active company's fiscal data: its CUIT is the receiver of its providers' documents.
Route::patch('/company/fiscal-data', [CompanyController::class, 'updateFiscalData']);
