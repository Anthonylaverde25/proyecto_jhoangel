<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Enums\AnimalSex;
use App\Core\ValueObjects\BreedingEligibility;
use DateTimeImmutable;

interface IBreedingEligibilityRepository
{
    /**
     * Own animals among the given ids, keyed by id.
     *
     * @param int[] $caravanIds
     * @return array<int, array{identification: string, sex: AnimalSex|null}>
     */
    public function findAnimals(array $caravanIds, int $companyId): array;

    /**
     * Set the owner's decision on every caravan and log one change per caravan.
     *
     * @param int[] $caravanIds
     */
    public function apply(
        array $caravanIds,
        int $companyId,
        BreedingEligibility $eligibility,
        ?string $note,
        ?int $userId,
        DateTimeImmutable $changedAt
    ): void;
}
