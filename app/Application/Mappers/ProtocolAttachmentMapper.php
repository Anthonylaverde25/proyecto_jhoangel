<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\ProtocolAttachmentEntity;
use App\Models\ProtocolAttachment;

final class ProtocolAttachmentMapper
{
    public static function toDomain(ProtocolAttachment $model): ProtocolAttachmentEntity
    {
        return new ProtocolAttachmentEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            diagnosticProtocolId: (int) $model->diagnostic_protocol_id,
            filePath: (string) $model->file_path,
            fileName: (string) $model->file_name,
            mimeType: (string) $model->mime_type,
            fileSize: (int) $model->file_size,
            checksumSha256: $model->checksum_sha256,
            needsConversion: (bool) $model->needs_conversion,
            uploadedByUserId: $model->uploaded_by_user_id !== null ? (int) $model->uploaded_by_user_id : null
        );
    }
}
