<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\TransferOrderAnimalStatus;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * One line of the roll: an animal the order commits, and the destination it was given.
 *
 * A null destination key is an answer, not a gap: the batch of this animal is decided at the
 * chute and its cell is printed blank on purpose.
 *
 * The target category is the same kind of answer, for orders whose category mode is DECLARED:
 * null means this animal keeps the category it has.
 */
final class TransferOrderAnimalEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly ?string $destinationKey,
        private TransferOrderAnimalStatus $status = TransferOrderAnimalStatus::PENDING,
        private ?DateTimeInterface $movedAt = null,
        private ?int $caravanMovementId = null,
        private readonly ?string $identification = null,
        private readonly ?string $sex = null,
        private readonly ?string $categoryName = null,
        private readonly ?int $currentBatchId = null,
        private readonly ?int $targetCategoryId = null,
        private readonly ?int $targetSubcategoryId = null,
        /** C/S label of the target, e.g. "Vaquillona / Reposición". Read-only, for printing. */
        private readonly ?string $targetCategoryLabel = null,
        /** C/S label of the category the animal has now. Read-only, for printing. */
        private readonly ?string $currentCategoryLabel = null
    ) {
    }

    public function getTargetCategoryId(): ?int
    {
        return $this->targetCategoryId;
    }

    public function getTargetSubcategoryId(): ?int
    {
        return $this->targetSubcategoryId;
    }

    public function getTargetCategoryLabel(): ?string
    {
        return $this->targetCategoryLabel;
    }

    public function getCurrentCategoryLabel(): ?string
    {
        return $this->currentCategoryLabel;
    }

    public function hasTargetCategory(): bool
    {
        return $this->targetCategoryId !== null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getDestinationKey(): ?string
    {
        return $this->destinationKey;
    }

    public function getStatus(): TransferOrderAnimalStatus
    {
        return $this->status;
    }

    public function getMovedAt(): ?DateTimeInterface
    {
        return $this->movedAt;
    }

    public function getCaravanMovementId(): ?int
    {
        return $this->caravanMovementId;
    }

    public function getIdentification(): ?string
    {
        return $this->identification;
    }

    public function getSex(): ?string
    {
        return $this->sex;
    }

    public function getCategoryName(): ?string
    {
        return $this->categoryName;
    }

    public function getCurrentBatchId(): ?int
    {
        return $this->currentBatchId;
    }

    public function isPending(): bool
    {
        return $this->status === TransferOrderAnimalStatus::PENDING;
    }

    public function markMoved(int $caravanMovementId, DateTimeInterface $at): void
    {
        $this->status = TransferOrderAnimalStatus::MOVED;
        $this->caravanMovementId = $caravanMovementId;
        $this->movedAt = DateTimeImmutable::createFromInterface($at);
    }

    public function markSkipped(): void
    {
        if ($this->isPending()) {
            $this->status = TransferOrderAnimalStatus::SKIPPED;
        }
    }
}
