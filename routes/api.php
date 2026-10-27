<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| This file only declares the security boundaries. Each bounded context lives in its own
| file under routes/api/ and inherits the middleware of the group that requires it, so a
| route added to an authenticated file is authenticated without having to remember it.
|
*/

Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function (): void {
    // Public on purpose: the credential travels in the request (login, invitation, signed URL).
    require __DIR__.'/api/public.php';

    // TODO(security): legacy endpoints still served without `auth:sanctum`. Move each route to
    // its bounded-context file below once no client calls it without a token.
    require __DIR__.'/api/legacy-unauthenticated.php';

    Route::middleware('auth:sanctum')->group(function (): void {
        require __DIR__.'/api/account.php';
        require __DIR__.'/api/herd.php';
        require __DIR__.'/api/assistant.php';
        require __DIR__.'/api/orders.php';
        require __DIR__.'/api/catalog.php';
        require __DIR__.'/api/stock.php';
        require __DIR__.'/api/service.php';
        require __DIR__.'/api/veterinary.php';
    });

    /*
     * Use Case 1 — Veterinary portal.
     *
     * A single group serves both accesses: the staff professional (user with the
     * `veterinarian` role in `company_user`) and the external one arriving by URL with a
     * temporary token (`X-Vet-Access-Token`). The middleware resolves both to
     * IVeterinaryPortalContext.
     */
    Route::middleware(['veterinary.portal', 'veterinary.portal.readonly'])
        ->prefix('veterinary-portal')
        ->group(__DIR__.'/api/veterinary-portal.php');
});
