<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Mcp\IssueMcpConnectionDTO;
use App\Application\Services\McpPublicUrls;
use App\Application\UseCases\Mcp\IssueMcpConnectionUseCase;
use App\Application\UseCases\Mcp\ListMcpConnectionsUseCase;
use App\Application\UseCases\Mcp\RevokeMcpConnectionUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mcp\IssueMcpConnectionRequest;
use App\Http\Resources\McpConnectionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The user's AI client connections (MCP), managed from /settings.
 */
class McpConnectionController extends Controller
{
    public function index(Request $request, ListMcpConnectionsUseCase $useCase, ICompanyContext $companyContext): AnonymousResourceCollection
    {
        // server_url: what the user pastes in "add custom connector" (Claude, Gemini) to connect by OAuth.
        return McpConnectionResource::collection(
            $useCase((int) $request->user()->getAuthIdentifier(), (int) $companyContext->getCompanyId())
        )->additional(['meta' => ['server_url' => McpPublicUrls::resource(McpPublicUrls::displayHostname($request->getHost()))]]);
    }

    public function store(
        IssueMcpConnectionRequest $request,
        IssueMcpConnectionUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        [$connection, $plainTextToken] = $useCase(new IssueMcpConnectionDTO(
            userId: (int) $request->user()->getAuthIdentifier(),
            companyId: (int) $companyContext->getCompanyId(),
            name: trim((string) $request->validated('name')),
            ttlDays: (int) $request->validated('ttl_days'),
        ));

        // The plaintext token travels only in this response; afterwards only its hash exists.
        return (new McpConnectionResource($connection))
            ->additional(['meta' => [
                'plain_text_token' => $plainTextToken,
                'server_url' => McpPublicUrls::resource(McpPublicUrls::displayHostname($request->getHost())),
            ]])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        int $id,
        RevokeMcpConnectionUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        $revoked = $useCase($id, (int) $request->user()->getAuthIdentifier(), (int) $companyContext->getCompanyId());

        return $revoked
            ? response()->json(null, 204)
            : response()->json(['message' => 'La conexión no existe.'], 404);
    }
}
