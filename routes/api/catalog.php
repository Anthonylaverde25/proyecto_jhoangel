<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ItemCategoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemPresentationController;
use App\Http\Controllers\Api\PartnerContactController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\UnitOfMeasureController;
use Illuminate\Support\Facades\Route;

// Catalog of items and services (Delivery B1): per company.
Route::controller(UnitOfMeasureController::class)->prefix('units-of-measure')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::patch('/{id}', 'update')->whereNumber('id');
    Route::patch('/{id}/active', 'setActive')->whereNumber('id');
});

Route::controller(ItemCategoryController::class)->prefix('item-categories')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::patch('/{id}', 'update')->whereNumber('id');
    Route::patch('/{id}/active', 'setActive')->whereNumber('id');
});

Route::controller(ItemController::class)->prefix('items')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::patch('/{id}', 'update')->whereNumber('id');
    Route::patch('/{id}/active', 'setActive')->whereNumber('id');
    Route::put('/{id}/providers', 'syncProviders')->whereNumber('id');
});

Route::controller(ItemPresentationController::class)->prefix('items/{id}/presentations')->group(function (): void {
    Route::post('/', 'store')->whereNumber('id');
    Route::patch('/{presentationId}', 'update')->whereNumber(['id', 'presentationId']);
    Route::patch('/{presentationId}/active', 'setActive')->whereNumber(['id', 'presentationId']);
});

// Suppliers and customers ("Clientes y proveedores"); its roles decide what it may be linked to (C2).
Route::controller(PartnerController::class)->prefix('partners')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::put('/{id}', 'update')->whereNumber('id');
    Route::patch('/{id}/roles', 'updateRoles')->whereNumber('id');
});

Route::controller(PartnerContactController::class)->prefix('partners/{id}/contacts')->group(function (): void {
    Route::post('/', 'store')->whereNumber('id');
    Route::put('/{contactId}', 'update')->whereNumber(['id', 'contactId']);
    Route::patch('/{contactId}/default', 'setDefault')->whereNumber(['id', 'contactId']);
    Route::delete('/{contactId}', 'destroy')->whereNumber(['id', 'contactId']);
});

// Transitional alias of /partners (egreso plan v5, 03 §2); the rest of /providers is still in
// legacy-unauthenticated.php.
Route::patch('/providers/{id}/roles', [ProviderController::class, 'updateRoles'])->whereNumber('id');
