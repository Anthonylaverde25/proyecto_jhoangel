<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Core\Entities\McpConnectionEntity;
use App\Core\Interfaces\IMcpConnectionRepository;

/**
 * Who stands behind an MCP access token: ai-agent asks this once per token (and caches it) to
 * learn the company every read must be pinned to.
 */
final class DescribeMcpSessionUseCase
{
    public function __construct(private readonly IMcpConnectionRepository $connections)
    {
    }

    public function __invoke(int $personalAccessTokenId): ?McpConnectionEntity
    {
        return $this->connections->findActiveByAccessTokenId($personalAccessTokenId);
    }
}
