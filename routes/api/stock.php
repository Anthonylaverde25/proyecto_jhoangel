<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\DeliveryNoteController;
use App\Http\Controllers\Api\FiscalCatalogController;
use App\Http\Controllers\Api\FiscalDiscrepancyController;
use App\Http\Controllers\Api\FiscalDocumentController;
use App\Http\Controllers\Api\ItemDocumentController;
use App\Http\Controllers\Api\ItemStockController;
use App\Http\Controllers\Api\SemenController;
use App\Http\Controllers\Api\ServiceOrderInseminationController;
use App\Http\Controllers\Api\StockIncidentController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
 * Provider documents and stock (Deliveries B2, C and D). Warehouses are editable; documents
 * and stock operations have no PUT/PATCH/DELETE: a mistake is corrected by voiding it.
 */

Route::get('/fiscal-document-types', [FiscalCatalogController::class, 'documentTypes']);
Route::get('/taxes', [FiscalCatalogController::class, 'taxes']);

Route::controller(FiscalDocumentController::class)->prefix('fiscal-documents')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::post('/read-image', 'readImage');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::post('/{id}/void', 'void')->whereNumber('id');
});

// Delivery C: delivery notes waiting for their invoice, the invoice they convert into, and their differences.
Route::controller(DeliveryNoteController::class)->prefix('delivery-notes')->group(function (): void {
    Route::get('/pending', 'pending');
    Route::get('/invoice-draft', 'invoiceDraft');
});

Route::controller(FiscalDiscrepancyController::class)->prefix('fiscal-discrepancies')->group(function (): void {
    Route::get('/', 'index');
    Route::patch('/{id}/resolve', 'resolve')->whereNumber('id');
});

Route::controller(WarehouseController::class)->prefix('warehouses')->group(function (): void {
    Route::get('/', 'index');
    Route::get('/next-code', 'nextCode');
    Route::get('/{id}/stock', 'stock')->whereNumber('id');
    Route::post('/', 'store');
    Route::patch('/{id}', 'update')->whereNumber('id');
    Route::patch('/{id}/default', 'setDefault')->whereNumber('id');
    Route::patch('/{id}/active', 'setActive')->whereNumber('id');
});

// Semen stock (semen S-A).
Route::controller(SemenController::class)->prefix('semen')->group(function (): void {
    Route::get('/sires', 'sires');
    Route::post('/sires', 'storeSire');
    Route::patch('/sires/{id}', 'updateSire')->whereNumber('id');
    Route::patch('/sires/{id}/active', 'setSireActive')->whereNumber('id');
    Route::post('/receipts', 'storeReceipt');
    Route::get('/inventory', 'inventory');
    Route::get('/lots', 'lots');
    Route::get('/lots/{id}', 'lot')->whereNumber('id');
    Route::get('/unidentified', 'unidentified');
    Route::post('/unidentified/{id}/identify', 'identify')->whereNumber('id');
});

Route::get('/semen/coverage', [ServiceOrderInseminationController::class, 'coverage']);
Route::get('/inseminations', [ServiceOrderInseminationController::class, 'search']);
Route::patch('/items/{itemId}/semen-profile', [SemenController::class, 'updateProfile'])->whereNumber('itemId');

Route::controller(ItemDocumentController::class)->prefix('item-documents')->group(function (): void {
    Route::get('/', 'index');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::post('/initial-stock', 'storeInitialStock');
    Route::post('/consumptions', 'storeConsumption');
    Route::post('/counts', 'storeCount');
    Route::post('/transfers', 'storeTransfer');
    Route::post('/{id}/reversal', 'reverse')->whereNumber('id');
});

Route::controller(ItemStockController::class)->prefix('items/{id}')->group(function (): void {
    Route::get('/stock', 'show')->whereNumber('id');
    Route::get('/kardex', 'kardex')->whereNumber('id');
    Route::patch('/stock/{warehouseId}/critical', 'setCritical')->whereNumber(['id', 'warehouseId']);
});

Route::controller(StockIncidentController::class)->prefix('stock-incidents')->group(function (): void {
    Route::get('/', 'index');
    Route::patch('/{id}/resolve', 'resolve')->whereNumber('id');
});

Route::get('/batches/{id}/consumption-history', [BatchController::class, 'consumptionHistory'])->whereNumber('id');
