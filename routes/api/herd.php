<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CaravanController;
use App\Http\Controllers\Api\HerdSummaryController;
use App\Http\Controllers\Api\LookupCaravansController;
use App\Http\Controllers\Api\OperationalKpiController;
use App\Http\Controllers\Api\RegisterNewCaravansController;
use Illuminate\Support\Facades\Route;

// Electronic reader at the chute: strict registration of new animals.
Route::post('/caravans/lookup', LookupCaravansController::class);
Route::get('/caravans/{id}', [CaravanController::class, 'show'])->whereNumber('id');
Route::post('/caravans/register-new', RegisterNewCaravansController::class);

Route::get('/dashboard/operational-kpis', [OperationalKpiController::class, 'index']);
Route::get('/dashboard/herd-summary', HerdSummaryController::class);
