<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BirthOrderController;
use App\Http\Controllers\Api\EntryOrderController;
use App\Http\Controllers\Api\TransferOrderController;
use App\Http\Controllers\Api\WeaningDeviationController;
use App\Http\Controllers\Api\WeaningOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Management orders: each one is issued, printed, executed (or registered from its scanned
 * planilla) and closed or cancelled.
 */

// Transfer orders (CACT-01).
Route::controller(TransferOrderController::class)->prefix('transfer-orders')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::post('/register', 'register');
    Route::get('/by-code/{code}', 'byCode');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::put('/{id}', 'update')->whereNumber('id');
    Route::post('/{id}/issue', 'issue')->whereNumber('id');
    Route::post('/{id}/printed', 'printed')->whereNumber('id');
    Route::post('/{id}/execute', 'execute')->whereNumber('id');
    Route::post('/{id}/close-incomplete', 'closeIncomplete')->whereNumber('id');
    Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
});

// Weaning orders (DEST-01).
Route::controller(WeaningOrderController::class)->prefix('weaning-orders')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::post('/register', 'register');
    Route::get('/by-code/{code}', 'byCode');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::put('/{id}', 'update')->whereNumber('id');
    Route::post('/{id}/issue', 'issue')->whereNumber('id');
    Route::post('/{id}/printed', 'printed')->whereNumber('id');
    Route::post('/{id}/execute', 'execute')->whereNumber('id');
    Route::post('/{id}/close-incomplete', 'closeIncomplete')->whereNumber('id');
    Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
});

// Weaning deviations from the order: recorded and reviewed, they never block.
Route::controller(WeaningDeviationController::class)->prefix('weaning-deviations')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/review', 'review');
});

// External livestock entry orders (ING-02): the purchase waits for its DTE.
Route::controller(EntryOrderController::class)->prefix('entry-orders')->group(function (): void {
    Route::get('/', 'index');
    Route::get('/next-number', 'nextNumber');
    Route::post('/', 'store')->middleware('dry.run');
    Route::post('/register', 'register')->middleware('dry.run');
    Route::get('/by-code/{code}', 'byCode');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::put('/{id}', 'update')->whereNumber('id');
    Route::post('/{id}/confirm', 'confirm')->whereNumber('id');
    Route::post('/{id}/printed', 'printed')->whereNumber('id');
    Route::post('/{id}/dtes', 'loadDte')->whereNumber('id');
    Route::patch('/{id}/dtes/{dteId}', 'correctDteHeadCount')->whereNumber(['id', 'dteId']);
    Route::post('/{id}/receive', 'receive')->whereNumber('id')->middleware('dry.run');
    Route::put('/{id}/destinations', 'redistribute')->whereNumber('id');
    Route::post('/{id}/pending-heads/write-off', 'writeOffPending')->whereNumber('id');
    Route::post('/{id}/tri', 'readTri')->whereNumber('id');
    Route::post('/{id}/receipt-sheets', 'issueReceiptSheet')->whereNumber('id');
    Route::post('/{id}/receipt-sheets/{sheetId}/printed', 'receiptSheetPrinted')->whereNumber(['id', 'sheetId']);
    Route::patch('/{id}/receipt-sheets/{sheetId}', 'configureReceiptSheet')->whereNumber(['id', 'sheetId']);
    Route::post('/{id}/incidents/{incidentId}/resolve', 'resolveIncident')->whereNumber(['id', 'incidentId']);
    Route::post('/{id}/close-incomplete', 'closeIncomplete')->whereNumber('id');
    Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
});

// Calving orders (PAR-01).
Route::controller(BirthOrderController::class)->prefix('birth-orders')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::post('/register', 'register');
    Route::get('/by-code/{code}', 'byCode');
    Route::get('/open-mothers', 'openMothers');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::put('/{id}', 'update')->whereNumber('id');
    Route::post('/{id}/issue', 'issue')->whereNumber('id');
    Route::post('/{id}/printed', 'printed')->whereNumber('id');
    Route::post('/{id}/execute', 'execute')->whereNumber('id');
    Route::post('/{id}/close-incomplete', 'closeIncomplete')->whereNumber('id');
    Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
});
