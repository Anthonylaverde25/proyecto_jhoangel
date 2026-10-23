<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Core\Entities\McpConnectionEntity;
use App\Core\Interfaces\IMcpConnectionRepository;

/**
 * The AI connections of the current user in the active company. Nobody sees or revokes the
 * connections of another user: each one answers for the clients they connected.
 */
final class ListMcpConnectionsUseCase
{
    public function __construct(private readonly IMcpConnectionRepository $connections)
    {
    }

    /** @return list<McpConnectionEntity> */
    public function __invoke(int $userId, int $companyId): array
    {
        return $this->connections->findAllForOwner($userId, $companyId);
    }
}
