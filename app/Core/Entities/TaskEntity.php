<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\TaskUrgency;
use App\Core\ValueObjects\TaskProviderRef;
use DateTimeImmutable;

/**
 * A to-do of a company: its text, how urgent it is and the providers it concerns.
 * No assignee, status or due date yet.
 */
final class TaskEntity
{
    public const MAX_DESCRIPTION_LENGTH = 2000;
    public const MAX_PROVIDERS = 20;

    /**
     * @param list<TaskProviderRef> $providers
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $description,
        private readonly TaskUrgency $urgency = TaskUrgency::NOT_URGENT,
        private readonly array $providers = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(int $companyId, string $description, TaskUrgency $urgency): self
    {
        return new self(null, $companyId, $description, $urgency);
    }

    /** The same task with a new text and/or urgency; null keeps the current value. */
    public function withChanges(?string $description, ?TaskUrgency $urgency): self
    {
        return new self(
            $this->id,
            $this->companyId,
            $description ?? $this->description,
            $urgency ?? $this->urgency,
            $this->providers,
            $this->createdAt,
            $this->updatedAt,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getUrgency(): TaskUrgency
    {
        return $this->urgency;
    }

    /** @return list<TaskProviderRef> */
    public function getProviders(): array
    {
        return $this->providers;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
