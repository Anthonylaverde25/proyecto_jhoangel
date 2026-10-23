<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Application\DTOs\Mcp\McpAuthorizationRequestDTO;
use App\Application\Services\McpAuthorizationRequestValidator;
use App\Application\Services\McpPublicUrls;
use App\Core\Entities\McpAuthorizationCodeEntity;
use App\Core\Exceptions\McpConnectionException;
use App\Core\Interfaces\IMcpAuthorizationCodeRepository;
use App\Core\Interfaces\IMcpTokenGateway;
use DateTimeImmutable;

/**
 * The user's answer on the consent screen, turned into the redirect back to the AI client:
 * an authorization code pinned to the user and the chosen company, or `access_denied`.
 */
final class ApproveMcpAuthorizationUseCase
{
    public function __construct(
        private readonly McpAuthorizationRequestValidator $validate,
        private readonly IMcpAuthorizationCodeRepository $codes,
    ) {
    }

    /** @return string the URL the browser must open next */
    public function __invoke(
        McpAuthorizationRequestDTO $request,
        string $hostname,
        int $userId,
        ?int $companyId,
        bool $approved
    ): string {
        $client = ($this->validate)($request, $hostname);
        // RFC 9207: `iss` must be the issuer the client discovered, not the host the SPA called.
        $issuer = McpPublicUrls::issuer($this->validate->resolveHostname($request, $hostname));

        if (!$approved) {
            return self::redirect($request->redirectUri, [
                'error' => 'access_denied',
                'error_description' => 'El usuario no autorizó la conexión.',
                'state' => $request->state,
                'iss' => $issuer,
            ]);
        }

        if ($companyId === null || $companyId <= 0) {
            throw McpConnectionException::companyRequired();
        }

        $code = bin2hex(random_bytes(32));
        $ttlMinutes = (int) config('livestock.mcp.oauth.code_ttl_minutes', 10);

        $this->codes->save(new McpAuthorizationCodeEntity(
            id: null,
            oauthClientId: $client->getId(),
            userId: $userId,
            companyId: $companyId,
            redirectUri: $request->redirectUri,
            codeChallenge: $request->codeChallenge,
            scope: IMcpTokenGateway::ABILITY,
            resource: $request->resource,
            expiresAt: (new DateTimeImmutable())->modify("+{$ttlMinutes} minutes"),
        ), hash('sha256', $code));

        return self::redirect($request->redirectUri, ['code' => $code, 'state' => $request->state, 'iss' => $issuer]);
    }

    /** @param array<string, string|null> $params */
    private static function redirect(string $redirectUri, array $params): string
    {
        $query = http_build_query(array_filter($params, static fn (?string $v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);

        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $query;
    }
}
