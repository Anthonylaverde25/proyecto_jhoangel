<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Interfaces\IMcpConnectionRepository;
use App\Core\Interfaces\IMcpTokenGateway;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows what a token minted for an AI client (MCP) can do, whatever route it reaches.
 *
 * Such a token belongs to a real user and, left alone, would read and write everything that
 * user can. Here it is pinned to reading, to the company its connection was issued for, and kept
 * away from the routes that manage connections or grant new ones. Session tokens (`*`) pass
 * untouched. Registered globally on the `api` group, so it also covers routes that still lack
 * `auth:sanctum`.
 */
final class RestrictMcpTokenScope
{
    private const READ_METHODS = ['GET', 'HEAD'];

    public function __construct(private readonly IMcpConnectionRepository $connections)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Runs before the route-level guard, as CompanyContextMiddleware does.
        $token = ($request->user() ?? auth('sanctum')->user())?->currentAccessToken();

        // A session token answers can('mcp:read') === true through '*': only an exact match is an MCP token.
        if (!$token instanceof PersonalAccessToken || $token->abilities !== [IMcpTokenGateway::ABILITY]) {
            return $next($request);
        }

        if (!in_array($request->method(), self::READ_METHODS, true)) {
            return $this->deny('Una conexión de IA sólo puede consultar; no modifica datos.');
        }

        $isSessionRoute = $request->is('api/mcp/session');
        if (!$isSessionRoute && ($request->is('api/mcp/*') || $request->is('api/assistant/*'))) {
            return $this->deny('Una conexión de IA no puede usar esta sección.');
        }

        $connection = $this->connections->findActiveByAccessTokenId((int) $token->getKey());
        if ($connection === null) {
            return response()->json(['message' => 'La conexión de IA fue revocada o venció.'], Response::HTTP_UNAUTHORIZED);
        }

        // The session endpoint is how ai-agent learns the company, so it is the only route without the header.
        if (!$isSessionRoute && (int) $request->header('X-Company-ID', '0') !== $connection->getCompanyId()) {
            return $this->deny('Esta conexión de IA pertenece a otra empresa.');
        }

        return $next($request);
    }

    private function deny(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
    }
}
