<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class WorkTemplateEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $typeId,
        private string $title,
        private ?string $description = null,
        private ?array $schemaDefinition = null,
        private string $status = 'active',
        private ?\DateTimeInterface $createdAt = null,
        private ?string $typeName = null,
        private ?string $typeColor = null,
        private ?string $typeIcon = null
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

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getSchemaDefinition(): ?array
    {
        return $this->schemaDefinition;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getTypeName(): ?string
    {
        return $this->typeName;
    }

    public function getTypeColor(): ?string
    {
        return $this->typeColor;
    }

    public function getTypeIcon(): ?string
    {
        return $this->typeIcon;
    }
}
