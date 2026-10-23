<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Mcp\McpAuthorizationRequestDTO;
use App\Application\UseCases\Mcp\ApproveMcpAuthorizationUseCase;
use App\Application\UseCases\Mcp\DescribeMcpAuthorizationRequestUseCase;
use App\Core\Exceptions\McpOAuthException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mcp\ApproveMcpAuthorizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SPA's consent screen for AI clients (OAuth). The user is logged in with their normal
 * session token; the company they pick travels as X-Company-ID, so CompanyContextMiddleware has
 * already checked they belong to it.
 */
class McpAuthorizationController extends Controller
{
    public function show(Request $request, DescribeMcpAuthorizationRequestUseCase $useCase): JsonResponse
    {
        try {
            return response()->json($useCase(McpAuthorizationRequestDTO::fromArray($request->query()), $request->getHost()));
        } catch (McpOAuthException $e) {
            return response()->json($e->toArray(), 400);
        }
    }

    public function approve(
        ApproveMcpAuthorizationRequest $request,
        ApproveMcpAuthorizationUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        try {
            $redirectUrl = $useCase(
                McpAuthorizationRequestDTO::fromArray($request->validated()),
                $request->getHost(),
                (int) $request->user()->getAuthIdentifier(),
                $companyContext->getCompanyId(),
                $request->validated('decision') === 'approve',
            );
        } catch (McpOAuthException $e) {
            return response()->json($e->toArray(), 400);
        }

        return response()->json(['redirect_url' => $redirectUrl]);
    }
}
