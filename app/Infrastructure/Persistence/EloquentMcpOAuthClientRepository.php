<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\McpOAuthMapper;
use App\Core\Entities\McpOAuthClientEntity;
use App\Core\Interfaces\IMcpOAuthClientRepository;
use App\Models\McpOAuthClient;

class EloquentMcpOAuthClientRepository implements IMcpOAuthClientRepository
{
    public function register(string $clientId, string $clientName, array $redirectUris): McpOAuthClientEntity
    {
        $model = McpOAuthClient::query()->create([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
        ]);

        return McpOAuthMapper::clientToDomain($model);
    }

    public function findByClientId(string $clientId): ?McpOAuthClientEntity
    {
        if ($clientId === '') {
            return null;
        }

        $model = McpOAuthClient::query()->where('client_id', $clientId)->first();

        return $model !== null ? McpOAuthMapper::clientToDomain($model) : null;
    }

    public function findById(int $id): ?McpOAuthClientEntity
    {
        $model = McpOAuthClient::query()->find($id);

        return $model !== null ? McpOAuthMapper::clientToDomain($model) : null;
    }
}
