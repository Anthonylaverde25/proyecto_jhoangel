<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\McpAuthorizationController;
use App\Http\Controllers\Api\McpConnectionController;
use App\Http\Controllers\Api\McpSessionController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

// Virtual assistant (drawer): read-only, forwarded to ai-agent on behalf of the user.
// No `throttle` yet: the database cache store has no table in tenant context (see AGT-03).
Route::post('/assistant/chat', [AssistantController::class, 'chat']);

// AI clients over MCP (Claude, Gemini): read-only connections served by ai-agent.
// RestrictMcpTokenScope keeps MCP tokens out of everything here but /mcp/session.
Route::prefix('mcp')->group(function (): void {
    Route::controller(McpConnectionController::class)->group(function (): void {
        Route::get('/connections', 'index');
        Route::post('/connections', 'store');
        Route::delete('/connections/{id}', 'destroy')->whereNumber('id');
    });

    // OAuth consent screen of the SPA.
    Route::controller(McpAuthorizationController::class)->group(function (): void {
        Route::get('/oauth/authorization-request', 'show');
        Route::post('/oauth/authorize', 'approve');
    });

    // Identity behind an MCP token, read by ai-agent's token verifier.
    Route::get('/session', McpSessionController::class);
});

// Tasks of the active company. Creating and editing tasks are the only writes an MCP token may perform.
Route::controller(TaskController::class)->prefix('tasks')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::patch('/{id}', 'update')->whereNumber('id');
});
