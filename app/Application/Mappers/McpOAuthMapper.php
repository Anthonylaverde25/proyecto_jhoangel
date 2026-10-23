<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\McpAuthorizationCodeEntity;
use App\Core\Entities\McpOAuthClientEntity;
use App\Models\McpOAuthAuthorizationCode;
use App\Models\McpOAuthClient;
use DateTimeImmutable;

final class McpOAuthMapper
{
    public static function clientToDomain(McpOAuthClient $model): McpOAuthClientEntity
    {
        return new McpOAuthClientEntity(
            id: (int) $model->id,
            clientId: (string) $model->client_id,
            clientName: (string) $model->client_name,
            redirectUris: array_values(array_map('strval', (array) $model->redirect_uris)),
        );
    }

    public static function codeToDomain(McpOAuthAuthorizationCode $model): McpAuthorizationCodeEntity
    {
        return new McpAuthorizationCodeEntity(
            id: (int) $model->id,
            oauthClientId: (int) $model->oauth_client_id,
            userId: (int) $model->user_id,
            companyId: (int) $model->company_id,
            redirectUri: (string) $model->redirect_uri,
            codeChallenge: (string) $model->code_challenge,
            scope: (string) $model->scope,
            resource: $model->resource,
            expiresAt: new DateTimeImmutable((string) $model->expires_at),
            usedAt: $model->used_at ? new DateTimeImmutable((string) $model->used_at) : null,
            connectionId: $model->mcp_connection_id !== null ? (int) $model->mcp_connection_id : null,
        );
    }
}
