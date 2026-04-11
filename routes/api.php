<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AzureDocumentController;
use App\Http\Controllers\Api\FieldMappingController;
use Illuminate\Support\Facades\Route;

Route::post('/analyze-table', AnalysisController::class);
Route::match(['get', 'post'], '/test/azure-layout', AzureDocumentController::class);

Route::get('/field-mappings/{model}', [FieldMappingController::class, 'index']);
Route::post('/field-mappings/learn', [FieldMappingController::class, 'learn']);
