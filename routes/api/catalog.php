<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ItemCategoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemPresentationController;
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

// A provider's roles decide what it may be linked to (D1, DB5).
Route::patch('/providers/{id}/roles', [ProviderController::class, 'updateRoles'])->whereNumber('id');
