<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ProtocolAttachmentEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin ProtocolAttachmentEntity
 */
class ProtocolAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProtocolAttachmentEntity $this */
        return [
            'id' => $this->getId(),
            'diagnostic_protocol_id' => $this->getDiagnosticProtocolId(),
            'file_name' => $this->getFileName(),
            'mime_type' => $this->getMimeType(),
            'file_size' => $this->getFileSize(),
            'checksum_sha256' => $this->getChecksumSha256(),
            // HEIC is accepted but browsers cannot render it; the UI uses this to warn instead
            // of showing a broken image (ADR-6).
            'needs_conversion' => $this->needsConversion(),
            // ADR-6: never a public URL. Short lived signed link, resolved server side.
            'download_url' => $this->buildSignedUrl(),
        ];
    }

    private function buildSignedUrl(): ?string
    {
        /** @var ProtocolAttachmentEntity $this */
        if ($this->getId() === null) {
            return null;
        }

        $ttl = (int) config('livestock.attachments.signed_url_ttl_minutes', 5);

        return URL::temporarySignedRoute(
            'protocol-attachments.download',
            now()->addMinutes($ttl),
            ['attachment' => $this->getId()]
        );
    }
}
