<?php

declare(strict_types=1);

use App\Http\Controllers\OAuth\McpOAuthServerController;
use Illuminate\Support\Facades\Route;

/*
| OAuth 2.1 authorization server for AI clients (MCP), one per tenant domain.
|
| Outside /api because the paths are fixed by the standards (RFC 8414 well-known metadata) and
| the clients build them from the issuer. Stateless: no session, no CSRF. No `throttle` yet for
| the same reason as the assistant (the database cache store has no table in tenant context,
| AGT-03); rate limiting of /oauth/register belongs to the reverse proxy until then.
*/

Route::get('/.well-known/oauth-authorization-server', [McpOAuthServerController::class, 'metadata']);
// Some clients probe OpenID discovery first; the same document answers both.
Route::get('/.well-known/openid-configuration', [McpOAuthServerController::class, 'metadata']);

Route::get('/oauth/authorize', [McpOAuthServerController::class, 'authorize']);
Route::post('/oauth/register', [McpOAuthServerController::class, 'register']);
Route::post('/oauth/token', [McpOAuthServerController::class, 'token']);
