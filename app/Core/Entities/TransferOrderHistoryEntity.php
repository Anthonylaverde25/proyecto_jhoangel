<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeInterface;

final class TransferOrderHistoryEntity
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        private readonly ?int $id,
        private readonly ?string $fromStatus,
        private readonly string $toStatus,
        private readonly ?int $actionUserId,
        private readonly ?string $actionUserName,
        private readonly ?string $reason,
        private readonly ?array $metadata,
        private readonly ?DateTimeInterface $createdAt
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function getActionUserId(): ?int
    {
        return $this->actionUserId;
    }

    public function getActionUserName(): ?string
    {
        return $this->actionUserName;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }
}
