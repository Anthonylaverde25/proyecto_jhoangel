<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

final class ServiceOrderEntity
{
    /**
     * @param int[] $maleCaravanIds
     * @param int[] $femaleCaravanIds
     * @param ServiceOrderHistoryEntity[] $history
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private int $batchId,
        private readonly string $code,
        private ServiceOrderStatus $status,
        private string $plannedStartDate,
        private ?int $requestedByUserId = null,
        private ?int $reviewedByUserId = null,
        private ?int $approvedByUserId = null,
        private ?DateTimeInterface $reviewedAt = null,
        private ?DateTimeInterface $approvedAt = null,
        private ?DateTimeInterface $executedAt = null,
        private ?string $actualStartDate = null,
        private ?string $actualEndDate = null,
        private ?string $observations = null,
        private ?string $rejectionReason = null,
        private array $maleCaravanIds = [],
        private array $femaleCaravanIds = [],
        private array $history = [],
        private ?DateTimeInterface $createdAt = null,
        private ?DateTimeInterface $updatedAt = null
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

    public function getBatchId(): int
    {
        return $this->batchId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStatus(): ServiceOrderStatus
    {
        return $this->status;
    }

    public function getRequestedByUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function getReviewedByUserId(): ?int
    {
        return $this->reviewedByUserId;
    }

    public function getApprovedByUserId(): ?int
    {
        return $this->approvedByUserId;
    }

    public function getReviewedAt(): ?DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function getApprovedAt(): ?DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function getExecutedAt(): ?DateTimeInterface
    {
        return $this->executedAt;
    }

    public function getPlannedStartDate(): string
    {
        return $this->plannedStartDate;
    }

    public function getActualStartDate(): ?string
    {
        return $this->actualStartDate;
    }

    public function getActualEndDate(): ?string
    {
        return $this->actualEndDate;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    /**
     * @return int[]
     */
    public function getMaleCaravanIds(): array
    {
        return $this->maleCaravanIds;
    }

    /**
     * @return int[]
     */
    public function getFemaleCaravanIds(): array
    {
        return $this->femaleCaravanIds;
    }

    /**
     * @return ServiceOrderHistoryEntity[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * Transition to PENDING_REVIEW (Submit for review)
     *
     * @throws ServiceOrderDomainException
     */
    public function submitForReview(): void
    {
        if ($this->status !== ServiceOrderStatus::DRAFT) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::PENDING_REVIEW->value);
        }

        if (empty($this->maleCaravanIds)) {
            throw ServiceOrderDomainException::domainError("Cannot submit order for review without bulls");
        }

        if (empty($this->femaleCaravanIds)) {
            throw ServiceOrderDomainException::domainError("Cannot submit order for review without females");
        }

        $this->status = ServiceOrderStatus::PENDING_REVIEW;
    }

    /**
     * Transition to PENDING_APPROVAL (Review passed)
     *
     * @throws ServiceOrderDomainException
     */
    public function reviewPass(int $userId): void
    {
        if ($this->status !== ServiceOrderStatus::PENDING_REVIEW) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::PENDING_APPROVAL->value);
        }

        $this->status = ServiceOrderStatus::PENDING_APPROVAL;
        $this->reviewedByUserId = $userId;
        $this->reviewedAt = new DateTimeImmutable();
    }

    /**
     * Transition to APPROVED (Approve order)
     *
     * @throws ServiceOrderDomainException
     */
    public function approve(int $userId): void
    {
        if ($this->status !== ServiceOrderStatus::PENDING_APPROVAL) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::APPROVED->value);
        }

        $this->status = ServiceOrderStatus::APPROVED;
        $this->approvedByUserId = $userId;
        $this->approvedAt = new DateTimeImmutable();
    }

    /**
     * Transition to REJECTED
     *
     * @throws ServiceOrderDomainException
     */
    public function reject(int $userId, string $reason): void
    {
        $allowed = [ServiceOrderStatus::PENDING_REVIEW, ServiceOrderStatus::PENDING_APPROVAL];
        if (!in_array($this->status, $allowed, true)) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::REJECTED->value);
        }

        if (trim($reason) === '') {
            throw ServiceOrderDomainException::domainError("Rejection reason cannot be empty");
        }

        $this->status = ServiceOrderStatus::REJECTED;
        $this->rejectionReason = $reason;
        
        // Log who rejected it
        if ($this->reviewedByUserId === null) {
            $this->reviewedByUserId = $userId;
            $this->reviewedAt = new DateTimeImmutable();
        } else {
            $this->approvedByUserId = $userId;
            $this->approvedAt = new DateTimeImmutable();
        }
    }

    /**
     * Transition to IN_PROGRESS (Execute order)
     *
     * @throws ServiceOrderDomainException
     */
    public function execute(): void
    {
        if ($this->status !== ServiceOrderStatus::APPROVED) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::IN_PROGRESS->value);
        }

        $this->status = ServiceOrderStatus::IN_PROGRESS;
        $this->executedAt = new DateTimeImmutable();
        $this->actualStartDate = (new DateTimeImmutable())->format('Y-m-d');
    }

    /**
     * Transition to COMPLETED
     *
     * @throws ServiceOrderDomainException
     */
    public function complete(?string $observations = null): void
    {
        if ($this->status !== ServiceOrderStatus::IN_PROGRESS) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::COMPLETED->value);
        }

        $this->status = ServiceOrderStatus::COMPLETED;
        $this->actualEndDate = (new DateTimeImmutable())->format('Y-m-d');
        if ($observations !== null) {
            $this->observations = $observations;
        }
    }

    /**
     * Transition to CANCELLED
     *
     * @throws ServiceOrderDomainException
     */
    public function cancel(): void
    {
        $allowed = [
            ServiceOrderStatus::DRAFT,
            ServiceOrderStatus::PENDING_REVIEW,
            ServiceOrderStatus::PENDING_APPROVAL,
            ServiceOrderStatus::APPROVED,
            ServiceOrderStatus::IN_PROGRESS
        ];

        if (!in_array($this->status, $allowed, true)) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::CANCELLED->value);
        }

        $this->status = ServiceOrderStatus::CANCELLED;
        if ($this->status === ServiceOrderStatus::IN_PROGRESS) {
            $this->actualEndDate = (new DateTimeImmutable())->format('Y-m-d');
        }
    }
}
