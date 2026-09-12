<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * An uploaded evidence file, already read out of the HTTP layer so the use case never
 * depends on `Illuminate\Http\UploadedFile`.
 */
final readonly class ProtocolAttachmentUploadDTO
{
    public function __construct(
        public string $fileName,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public string $contents
    ) {
    }

    public function checksum(): string
    {
        return hash('sha256', $this->contents);
    }

    /**
     * HEIC photos from iPhone reach WhatsApp unchanged and browsers cannot render them.
     * They are accepted and flagged; conversion is out of scope for this iteration.
     */
    public function needsConversion(): bool
    {
        return in_array(strtolower($this->extension), ['heic', 'heif'], true);
    }
}
