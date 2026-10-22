<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\WeaningDeviationKind;
use App\Core\Exceptions\WeaningOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * One calf of a weaning order that was weaned (or not) differently from what the order planned,
 * and its review. It never blocks anything: it is written after the fact, then read and reviewed —
 * somebody says they saw it and why it is fine.
 */
final class WeaningOrderDeviationEntity
{
    /**
     * @param array<string, mixed> $metadata the codes behind the labels
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $weaningOrderId,
        private readonly int $weaningOrderAnimalId,
        private readonly int $caravanId,
        private readonly WeaningDeviationKind $kind,
        private readonly ?string $planned,
        private readonly ?string $actual,
        private readonly array $metadata,
        private readonly DateTimeInterface $occurredAt,
        private readonly ?int $recordedByUserId = null,
        private ?DateTimeInterface $reviewedAt = null,
        private ?int $reviewedByUserId = null,
        private ?string $reviewNote = null,
        private readonly ?string $orderCode = null,
        private readonly ?string $orderStatus = null,
        private readonly ?string $identification = null,
        private readonly ?string $sex = null,
        private readonly ?string $sourceBatchName = null,
        private readonly ?string $recordedByUserName = null,
        private readonly ?string $reviewedByUserName = null
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function record(
        WeaningOrderEntity $order,
        WeaningOrderAnimalEntity $line,
        WeaningDeviationKind $kind,
        ?string $planned,
        ?string $actual,
        array $metadata,
        DateTimeInterface $occurredAt,
        ?int $userId
    ): self {
        return new self(
            id: null,
            companyId: $order->getCompanyId(),
            weaningOrderId: (int) $order->getId(),
            weaningOrderAnimalId: (int) $line->getId(),
            caravanId: $line->getCaravanId(),
            kind: $kind,
            planned: $planned,
            actual: $actual,
            metadata: $metadata,
            occurredAt: $occurredAt,
            recordedByUserId: $userId
        );
    }

    /**
     * Somebody saw it and says why. Reviewing twice keeps the first review.
     *
     * @throws WeaningOrderDomainException
     */
    public function review(string $note, ?int $userId): void
    {
        if (trim($note) === '') {
            throw WeaningOrderDomainException::domainError('Escribí la justificación de la revisión.', 'REVIEW_NOTE_REQUIRED');
        }

        if ($this->isReviewed()) {
            return;
        }

        $this->reviewNote = trim($note);
        $this->reviewedByUserId = $userId;
        $this->reviewedAt = new DateTimeImmutable();
    }

    public function isReviewed(): bool
    {
        return $this->reviewedAt !== null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getWeaningOrderId(): int
    {
        return $this->weaningOrderId;
    }

    public function getWeaningOrderAnimalId(): int
    {
        return $this->weaningOrderAnimalId;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getKind(): WeaningDeviationKind
    {
        return $this->kind;
    }

    public function getPlanned(): ?string
    {
        return $this->planned;
    }

    public function getActual(): ?string
    {
        return $this->actual;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOccurredAt(): DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function getRecordedByUserId(): ?int
    {
        return $this->recordedByUserId;
    }

    public function getReviewedAt(): ?DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function getReviewedByUserId(): ?int
    {
        return $this->reviewedByUserId;
    }

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }

    public function getOrderCode(): ?string
    {
        return $this->orderCode;
    }

    public function getOrderStatus(): ?string
    {
        return $this->orderStatus;
    }

    public function getIdentification(): ?string
    {
        return $this->identification;
    }

    public function getSex(): ?string
    {
        return $this->sex;
    }

    public function getSourceBatchName(): ?string
    {
        return $this->sourceBatchName;
    }

    public function getRecordedByUserName(): ?string
    {
        return $this->recordedByUserName;
    }

    public function getReviewedByUserName(): ?string
    {
        return $this->reviewedByUserName;
    }
}
