<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\WeaningOrderAnimalStatus;
use App\Core\Enums\WeaningType;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * One calf of a weaning order: the breeding batch it leaves, the weaning batch it was given and,
 * in a DECLARED order, the category it takes.
 *
 * A null destination key is an answer, not a gap: the weaning batch of this calf is decided at
 * the chute and its cell is printed blank on purpose. A null target category means the calf keeps
 * the category it has.
 */
final class WeaningOrderAnimalEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly ?int $sourceBatchId,
        private ?string $destinationKey,
        private WeaningOrderAnimalStatus $status = WeaningOrderAnimalStatus::PENDING,
        private ?DateTimeInterface $weanedAt = null,
        private ?int $caravanMovementId = null,
        private ?int $targetCategoryId = null,
        private ?int $targetSubcategoryId = null,
        private readonly ?string $identification = null,
        private readonly ?string $sex = null,
        private readonly ?string $motherIdentification = null,
        private readonly ?string $sourceBatchName = null,
        private readonly ?int $currentBatchId = null,
        /** C/S label of the target, e.g. "Vaquillona / Reposición". Read-only, for printing. */
        private readonly ?string $targetCategoryLabel = null,
        /** C/S label of the category the calf has now. Read-only, for printing. */
        private readonly ?string $currentCategoryLabel = null,
        private readonly ?string $birthDate = null,
        /** The C/S the calf has now: the reference a new one is chosen against. Read-only. */
        private readonly ?int $currentCategoryId = null,
        private readonly ?int $currentSubcategoryId = null,
        /**
         * Where the order planned to send the calf, kept when it went elsewhere. Null on a line not
         * saved yet (the plan is its destination) or one decided at the chute.
         */
        private readonly ?string $plannedDestinationKey = null,
        /** How it was actually weaned, from its lineage. Read-only. */
        private readonly ?WeaningType $weaningTypeApplied = null,
        /** Its deviations from the plan: null = none, else whether they all were reviewed. Read-only. */
        private readonly ?bool $deviationsReviewed = null
    ) {
    }

    public function hasDeviations(): bool
    {
        return $this->deviationsReviewed !== null;
    }

    public function areDeviationsReviewed(): ?bool
    {
        return $this->deviationsReviewed;
    }

    /** What the order planned: for a line not saved yet, the destination it was created with. */
    public function getPlannedDestinationKey(): ?string
    {
        return $this->id === null ? $this->destinationKey : $this->plannedDestinationKey;
    }

    /** The calf went somewhere other than where the order planned. */
    public function isRedirected(): bool
    {
        $planned = $this->getPlannedDestinationKey();

        return $planned !== null && $this->destinationKey !== null && $planned !== $this->destinationKey;
    }

    public function getWeaningTypeApplied(): ?WeaningType
    {
        return $this->weaningTypeApplied;
    }

    public function getCurrentCategoryId(): ?int
    {
        return $this->currentCategoryId;
    }

    public function getCurrentSubcategoryId(): ?int
    {
        return $this->currentSubcategoryId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getSourceBatchId(): ?int
    {
        return $this->sourceBatchId;
    }

    /**
     * The batch it actually went to, declared when it was weaned: it may differ from the plan.
     */
    public function redirectTo(string $destinationKey): void
    {
        $this->destinationKey = $destinationKey;
    }

    public function getDestinationKey(): ?string
    {
        return $this->destinationKey;
    }

    public function getStatus(): WeaningOrderAnimalStatus
    {
        return $this->status;
    }

    public function getWeanedAt(): ?DateTimeInterface
    {
        return $this->weanedAt;
    }

    public function getCaravanMovementId(): ?int
    {
        return $this->caravanMovementId;
    }

    public function getTargetCategoryId(): ?int
    {
        return $this->targetCategoryId;
    }

    public function getTargetSubcategoryId(): ?int
    {
        return $this->targetSubcategoryId;
    }

    public function hasTargetCategory(): bool
    {
        return $this->targetCategoryId !== null;
    }

    public function getIdentification(): ?string
    {
        return $this->identification;
    }

    public function getSex(): ?string
    {
        return $this->sex;
    }

    public function getMotherIdentification(): ?string
    {
        return $this->motherIdentification;
    }

    public function getSourceBatchName(): ?string
    {
        return $this->sourceBatchName;
    }

    public function getCurrentBatchId(): ?int
    {
        return $this->currentBatchId;
    }

    public function getTargetCategoryLabel(): ?string
    {
        return $this->targetCategoryLabel;
    }

    public function getCurrentCategoryLabel(): ?string
    {
        return $this->currentCategoryLabel;
    }

    public function getBirthDate(): ?string
    {
        return $this->birthDate;
    }

    public function isPending(): bool
    {
        return $this->status === WeaningOrderAnimalStatus::PENDING;
    }

    /**
     * @param array{0: int, 1: ?int}|null $decidedCategory the C/S decided at the chute, when the
     *        order left it for then: it stays on the line as what the order ended up saying.
     */
    public function markWeaned(int $caravanMovementId, DateTimeInterface $at, ?array $decidedCategory = null): void
    {
        if ($decidedCategory !== null) {
            [$this->targetCategoryId, $this->targetSubcategoryId] = $decidedCategory;
        }

        $this->status = WeaningOrderAnimalStatus::WEANED;
        $this->caravanMovementId = $caravanMovementId;
        $this->weanedAt = DateTimeImmutable::createFromInterface($at);
    }

    public function markSkipped(): void
    {
        if ($this->isPending()) {
            $this->status = WeaningOrderAnimalStatus::SKIPPED;
        }
    }
}
