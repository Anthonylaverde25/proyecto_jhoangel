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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\CompanyContextMiddleware::class,
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
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['domain' => [$exception->getMessage()]],
                ], 422);
            }

            return null;
        });
    })->create();
