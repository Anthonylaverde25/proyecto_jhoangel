<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class WorkTemplateEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private string $category,
        private string $title,
        private ?string $description = null,
        private ?array $schemaDefinition = null,
        private string $status = 'active',
        private ?string $code = null,
        private ?\DateTimeInterface $createdAt = null
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

    public function getCategory(): string
    {
        return $this->category;
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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
