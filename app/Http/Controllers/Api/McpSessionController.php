<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Mcp\DescribeMcpSessionUseCase;
use App\Core\Interfaces\IMcpTokenGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who stands behind an MCP access token. ai-agent's token verifier calls it on the tenant the
 * AI client dialled; Sanctum has already rejected unknown, revoked or expired tokens with 401.
 */
class McpSessionController extends Controller
{
    public function __invoke(Request $request, DescribeMcpSessionUseCase $useCase): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if (!$token instanceof PersonalAccessToken || $token->abilities !== [IMcpTokenGateway::ABILITY]) {
            return response()->json(['message' => 'Este endpoint sólo responde a conexiones de IA.'], 403);
        }

        $connection = $useCase((int) $token->getKey());
        if ($connection === null) {
            return response()->json(['message' => 'La conexión de IA fue revocada o venció.'], 401);
        }

        return response()->json([
            'connection_id' => $connection->getId(),
            'name' => $connection->getName(),
            'source' => $connection->getSource(),
            'user' => ['id' => $connection->getUserId(), 'name' => $connection->getUserName()],
            'company' => ['id' => $connection->getCompanyId(), 'name' => $connection->getCompanyName()],
            'abilities' => [IMcpTokenGateway::ABILITY],
            'expires_at' => $token->expires_at?->toAtomString(),
        ]);
    }
}
