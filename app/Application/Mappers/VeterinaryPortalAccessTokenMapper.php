<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use App\Models\VeterinaryPortalAccessToken;
use DateTimeImmutable;

final class VeterinaryPortalAccessTokenMapper
{
    /**
     * @param list<int> $allowedBatchIds
     * @param list<int> $allowedProtocolIds
     */
    public static function toDomain(
        VeterinaryPortalAccessToken $model,
        array $allowedBatchIds = [],
        ?string $plainToken = null,
        array $allowedProtocolIds = []
    ): VeterinaryPortalAccessTokenEntity {
        $veterinarian = $model->relationLoaded('veterinarian') ? $model->veterinarian : null;
        $healthCenter = $model->relationLoaded('healthCenter') ? $model->healthCenter : null;
        $batch = $model->relationLoaded('batch') ? $model->batch : null;
        $protocol = $model->relationLoaded('diagnosticProtocol') ? $model->diagnosticProtocol : null;

        return new VeterinaryPortalAccessTokenEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            veterinarianId: (int) $model->veterinarian_id,
            tokenHash: (string) $model->token_hash,
            tokenPrefix: (string) $model->token_prefix,
            expiresAt: new DateTimeImmutable((string) $model->expires_at),
            batchId: $model->batch_id !== null ? (int) $model->batch_id : null,
            diagnosticProtocolId: $model->diagnostic_protocol_id !== null ? (int) $model->diagnostic_protocol_id : null,
            label: $model->label,
            maxUses: $model->max_uses !== null ? (int) $model->max_uses : null,
            usedCount: (int) $model->used_count,
            lastUsedAt: $model->last_used_at ? new DateTimeImmutable((string) $model->last_used_at) : null,
            revokedAt: $model->revoked_at ? new DateTimeImmutable((string) $model->revoked_at) : null,
            createdByUserId: $model->created_by_user_id !== null ? (int) $model->created_by_user_id : null,
            veterinarianName: $veterinarian?->name,
            licenseNumber: $veterinarian?->license_number,
            healthCenterName: $healthCenter?->name,
            batchName: $batch?->name,
            protocolNumber: $protocol?->protocol_number,
            allowedBatchIds: $allowedBatchIds,
            allowedProtocolIds: $allowedProtocolIds,
            plainToken: $plainToken
        );
    }
}
