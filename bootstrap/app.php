<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // OAuth server for AI clients (MCP): tenant by domain, stateless, no /api prefix.
            \Illuminate\Support\Facades\Route::middleware([
                \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
                \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            ])->group(base_path('routes/mcp-oauth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'oauth/*',
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\CompanyContextMiddleware::class,
            // AI client tokens (MCP): read only, pinned to their company.
            \App\Http\Middleware\RestrictMcpTokenScope::class,
        ]);

        $middleware->alias([
            // Single door to the veterinary portal: authenticated staff vet or external
            // professional carrying a temporary access token.
            'veterinary.portal' => \App\Http\Middleware\ResolveVeterinaryPortalAccess::class,

            // A management session reading somebody's portal writes nothing in their name.
            'veterinary.portal.readonly' => \App\Http\Middleware\DenyReadOnlyPortalWrites::class,

            // "What would happen?": runs the request and rolls it back (X-Dry-Run: 1).
            'dry.run' => \App\Http\Middleware\DryRunTransaction::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // AGENT.md 3.5: a broken business invariant is a 422, never a 500.
        $exceptions->render(function (\App\Core\Exceptions\DomainException $exception, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                // A count confirmed against a balance that changed meanwhile (stock DS6).
                if ($exception instanceof \App\Core\Exceptions\StaleCountException) {
                    return response()->json([
                        'message' => $exception->getMessage(),
                        'current_quantity' => $exception->getCurrentQuantity(),
                    ], 409);
                }

                return response()->json([
                    'message' => $exception->getMessage(),
                    // A stable reason ("UNTAGGED_ANIMAL") for the exceptions that carry one.
                    ...(method_exists($exception, 'getErrorCode') ? ['code' => $exception->getErrorCode()] : []),
                    // Field keys ("lines.3.quantity") let a sheet mark the offending cells.
                    'errors' => $exception instanceof \App\Core\Exceptions\FieldValidationException
                        ? $exception->getErrors()
                        : ['domain' => [$exception->getMessage()]],
                ], 422);
            }

            return null;
        });
    })->create();
