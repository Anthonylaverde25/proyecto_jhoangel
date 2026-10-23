<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use App\Application\DTOs\Mcp\ExchangeMcpAuthorizationCodeDTO;
use App\Application\DTOs\Mcp\McpAuthorizationRequestDTO;
use App\Application\Services\McpAuthorizationRequestValidator;
use App\Application\Services\McpPublicUrls;
use App\Application\UseCases\Mcp\ExchangeMcpAuthorizationCodeUseCase;
use App\Application\UseCases\Mcp\RefreshMcpAccessTokenUseCase;
use App\Application\UseCases\Mcp\RegisterMcpOAuthClientUseCase;
use App\Core\Exceptions\McpOAuthException;
use App\Core\Interfaces\IMcpTokenGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OAuth 2.1 authorization server for AI clients (the MCP authorization spec), one per tenant.
 *
 * - RFC 8414 metadata, so Claude or Gemini discover the endpoints from the issuer alone.
 * - RFC 7591 dynamic registration, so any MCP client can introduce itself.
 * - Authorization code + PKCE S256; the login and consent happen in the SPA.
 * - Rotating refresh tokens.
 *
 * Answers use the OAuth error shape (`error`, `error_description`), not the API's 422 envelope.
 */
class McpOAuthServerController extends Controller
{
    public function metadata(Request $request): JsonResponse
    {
        $issuer = McpPublicUrls::issuer($request->getHost());

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [IMcpTokenGateway::ABILITY],
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /**
     * The browser lands here from the AI client. Once the client and its redirect URI are
     * trusted, the request moves on to the SPA consent screen, where the user logs in if needed.
     */
    public function authorize(Request $request, McpAuthorizationRequestValidator $validate): RedirectResponse|JsonResponse
    {
        $authRequest = McpAuthorizationRequestDTO::fromArray($request->query());

        try {
            $validate($authRequest, $request->getHost());
        } catch (McpOAuthException $e) {
            if (!McpAuthorizationRequestValidator::isRedirectable($e)) {
                return response()->json($e->toArray(), 400);
            }

            $query = http_build_query(array_filter([
                ...$e->toArray(),
                'state' => $authRequest->state,
                'iss' => McpPublicUrls::issuer($request->getHost()),
            ]), '', '&', PHP_QUERY_RFC3986);

            return redirect()->away($authRequest->redirectUri . (str_contains($authRequest->redirectUri, '?') ? '&' : '?') . $query);
        }

        // The consent screen may call this API on another host (localhost in dev): it carries the
        // host the client used, so the code and `iss` refer to the issuer the client discovered.
        $consent = McpPublicUrls::consent($request->getHost());
        $query = $authRequest->withIssuerHost($request->getHost())->toQuery();

        return redirect()->away($consent . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function register(Request $request, RegisterMcpOAuthClientUseCase $useCase): JsonResponse
    {
        try {
            $client = $useCase($request->json()->all());
        } catch (McpOAuthException $e) {
            return response()->json($e->toArray(), $e->getStatus() === 401 ? 400 : $e->getStatus());
        }

        return response()->json([
            'client_id' => $client->getClientId(),
            'client_name' => $client->getClientName(),
            'redirect_uris' => $client->getRedirectUris(),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => IMcpTokenGateway::ABILITY,
            'client_id_issued_at' => time(),
        ], 201);
    }

    public function token(
        Request $request,
        ExchangeMcpAuthorizationCodeUseCase $exchange,
        RefreshMcpAccessTokenUseCase $refresh
    ): JsonResponse {
        $grantType = (string) $request->input('grant_type', '');
        $clientId = (string) $request->input('client_id', '');

        try {
            $grant = match ($grantType) {
                'authorization_code' => $exchange(new ExchangeMcpAuthorizationCodeDTO(
                    clientId: $clientId,
                    code: (string) $request->input('code', ''),
                    redirectUri: (string) $request->input('redirect_uri', ''),
                    codeVerifier: (string) $request->input('code_verifier', ''),
                    resource: $request->filled('resource') ? (string) $request->input('resource') : null,
                ), $request->getHost()),
                'refresh_token' => $refresh($clientId, (string) $request->input('refresh_token', '')),
                default => throw McpOAuthException::unsupportedGrantType($grantType),
            };
        } catch (McpOAuthException $e) {
            return $this->noStore(response()->json($e->toArray(), $e->getStatus()));
        }

        return $this->noStore(response()->json($grant->toArray()));
    }

    /** RFC 6749 §5.1: token responses must not be cached. */
    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
