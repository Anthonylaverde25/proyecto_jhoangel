<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeInterface;

final class ServiceOrderHistoryEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $serviceOrderId,
        private readonly ?string $fromStatus,
        private readonly string $toStatus,
        private readonly int $actionUserId,
        private readonly ?string $actionReason = null,
        private readonly ?array $actionMetadata = null,
        private readonly ?DateTimeInterface $createdAt = null
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

    public function getServiceOrderId(): int
    {
        return $this->serviceOrderId;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function getActionUserId(): int
    {
        return $this->actionUserId;
    }

    public function getActionReason(): ?string
    {
        return $this->actionReason;
    }

    public function getActionMetadata(): ?array
    {
        return $this->actionMetadata;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }
}
