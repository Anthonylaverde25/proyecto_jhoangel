<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class ProtocolAttachmentEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $diagnosticProtocolId,
        private readonly string $filePath,
        private readonly string $fileName,
        private readonly string $mimeType,
        private readonly int $fileSize,
        private readonly ?string $checksumSha256 = null,
        private readonly bool $needsConversion = false,
        private readonly ?int $uploadedByUserId = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getDiagnosticProtocolId(): int
    {
        return $this->diagnosticProtocolId;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getChecksumSha256(): ?string
    {
        return $this->checksumSha256;
    }

    public function needsConversion(): bool
    {
        return $this->needsConversion;
    }

    public function getUploadedByUserId(): ?int
    {
        return $this->uploadedByUserId;
    }
}
