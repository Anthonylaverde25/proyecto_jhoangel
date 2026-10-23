<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\McpConnectionEntity;
use App\Models\McpConnection;
use DateTimeImmutable;

final class McpConnectionMapper
{
    public static function toDomain(McpConnection $model): McpConnectionEntity
    {
        $token = $model->relationLoaded('accessToken') ? $model->accessToken : null;
        $user = $model->relationLoaded('user') ? $model->user : null;
        $company = $model->relationLoaded('company') ? $model->company : null;

        return new McpConnectionEntity(
            id: (int) $model->id,
            userId: (int) $model->user_id,
            companyId: (int) $model->company_id,
            source: (string) $model->source,
            name: (string) $model->name,
            expiresAt: new DateTimeImmutable((string) $model->expires_at),
            personalAccessTokenId: $model->personal_access_token_id !== null ? (int) $model->personal_access_token_id : null,
            oauthClientId: $model->oauth_client_id !== null ? (int) $model->oauth_client_id : null,
            revokedAt: $model->revoked_at ? new DateTimeImmutable((string) $model->revoked_at) : null,
            createdAt: $model->created_at ? new DateTimeImmutable((string) $model->created_at) : null,
            lastUsedAt: $token?->last_used_at ? new DateTimeImmutable((string) $token->last_used_at) : null,
            userName: $user?->name,
            companyName: $company?->name,
        );
    }
}
