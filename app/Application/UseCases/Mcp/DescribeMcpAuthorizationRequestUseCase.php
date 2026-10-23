<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Application\DTOs\Mcp\McpAuthorizationRequestDTO;
use App\Application\Services\McpAuthorizationRequestValidator;

/**
 * What the consent screen shows: which client asks, where it will return and what it may do.
 */
final class DescribeMcpAuthorizationRequestUseCase
{
    public function __construct(private readonly McpAuthorizationRequestValidator $validate)
    {
    }

    /** @return array{client_name: string, redirect_host: string, scope: string} */
    public function __invoke(McpAuthorizationRequestDTO $request, string $hostname): array
    {
        $client = ($this->validate)($request, $hostname);

        return [
            'client_name' => $client->getClientName(),
            'redirect_host' => (string) (parse_url($request->redirectUri, PHP_URL_HOST) ?: parse_url($request->redirectUri, PHP_URL_SCHEME)),
            'scope' => 'mcp:read',
        ];
    }
}
