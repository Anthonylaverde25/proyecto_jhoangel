<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\McpOAuthClientEntity;

interface IMcpOAuthClientRepository
{
    /** @param list<string> $redirectUris */
    public function register(string $clientId, string $clientName, array $redirectUris): McpOAuthClientEntity;

    public function findByClientId(string $clientId): ?McpOAuthClientEntity;

    public function findById(int $id): ?McpOAuthClientEntity;
}
