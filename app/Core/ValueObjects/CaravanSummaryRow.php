<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * One line of the light caravan list: what an inventory table shows, without the lineage and
 * gestation trees of the full entity.
 *
 * `gestationStatus` is PREGNANT when the female carries a current gestation, NONE when she does
 * not (empty or never diagnosed: the system cannot tell them apart), and null for a male or for a
 * category that does not breed (a calf).
 *
 * `isReproductiveCategory` is the catalog's word on the category; null when it has none.
 */
final readonly class CaravanSummaryRow
{
    public function __construct(
        public int $id,
        /** Null while a calf born here waits for its tag. */
        public ?string $identification,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?int $subcategoryId,
        public ?string $subcategoryName,
        public ?int $teeth,
        public ?float $entryWeight,
        public ?float $currentWeight,
        public ?int $breedId,
        public ?string $breedName,
        public string $sex,
        public ?string $entryDate,
        public ?int $batchId,
        public ?string $batchName,
        public ?string $farmName,
        public ?\DateTimeImmutable $batchAssignedAt,
        public ?string $gestationStatus,
        public bool $isBreedingEligible = true,
        public ?string $breedingExclusionReason = null,
        public ?bool $isReproductiveCategory = null,
        /** The tag, or "Cría de 4521 ♀" while it has none. */
        public string $label = '',
        /** The day it died and the cause, for an animal registered dead. */
        public ?string $deathDate = null,
        public ?string $deathCause = null,
    ) {
    }
}
