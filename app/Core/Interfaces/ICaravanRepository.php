<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\CaravanEntity;
use App\Core\ValueObjects\CaravanNumber;

interface ICaravanRepository
{
    /**
     * @param CaravanEntity $caravan
     * @return CaravanEntity
     */
    public function save(CaravanEntity $caravan): CaravanEntity;

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity;

    /**
     * Resolves many caravans by identification in a single query.
     *
     * A scanned sheet carries a whole troop, so looking them up one by one turns a
     * page of 200 head into 200 round trips. The keys of the returned map are the
     * identifications upper-cased and trimmed, so the caller can look up a raw OCR
     * reading without normalising twice.
     *
     * @param string[] $identifications
     * @return array<string, CaravanEntity>
     */
    public function findByIdentifications(array $identifications): array;

    /**
     * @param CaravanNumber $identification
     * @return CaravanEntity|null
     */
    public function findByIdentificationGlobal(CaravanNumber $identification): ?CaravanEntity;

    /**
     * @param int $id
     * @return CaravanEntity|null
     */
    public function findById(int $id): ?CaravanEntity;

    /**
     * Ids, among the given ones, of caravans sitting in a batch whose farm belongs to a provider.
     *
     * @param int[] $caravanIds
     * @return int[]
     */
    public function findIdsInExternalBatches(array $caravanIds): array;

    /**
     * @param string|null $scope 'own' | 'external' | 'all'
     * @return CaravanEntity[]
     */
    public function findAll(?string $scope = 'own'): array;


    /**
     * @param int $batchId
     * @return int
     */
    public function countByBatch(int $batchId): int;

    /**
     * @param int $batchId
     * @return float|null
     */
    public function getAverageWeightByBatch(int $batchId): ?float;

    /**
     * @param int $batchId
     * @return float|null
     */
    public function getMinWeightByBatch(int $batchId): ?float;

    /**
     * @param int $batchId
     * @return float|null
     */
    public function getMaxWeightByBatch(int $batchId): ?float;

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * @return \App\Core\Entities\BirthHistoryEntity[]
     */
    public function findBirthHistory(): array;

    /**
     * Update the batch assignment and optionally the category/subcategory IDs of a caravan.
     */
    public function updateBatchAndCategory(int $caravanId, int $batchId, ?int $categoryId = null, ?int $subcategoryId = null): void;

    /**
     * Narrow write of the dentition read at the chute.
     *
     * Deliberately not a full `save()`: that rewrites the reproductive detail and every
     * gestation of the animal from the entity in hand, which is far more than a sheet
     * that measured a mouth is entitled to touch.
     */
    public function updateTeeth(int $caravanId, int $teeth): void;

    /**
     * Moves caravans into a batch and returns EVERY batch whose membership changed,
     * the source ones included.
     *
     * This is the only supported way to change the batch of an animal. The aggregate
     * weight of a batch is a derived value of its current membership, so returning the
     * affected batches is what keeps a caller from silently leaving one of them stale.
     *
     * @param  int[] $caravanIds
     * @return int[] ids of the affected batches (sources and target)
     */
    public function moveCaravansToBatch(
        array $caravanIds,
        int $targetBatchId,
        ?int $categoryId = null,
        ?int $subcategoryId = null
    ): array;

    /**
     * How many head of the batch have a current weight. This is the set the average is
     * computed over, and it may be smaller than the batch itself.
     */
    public function countWeighedByBatch(int $batchId): int;

    /**
     * Measured mass of the batch: the sum over the animals with a current weight.
     * Unlike the average, this is additive and conserved when a batch is split.
     */
    public function getTotalWeightByBatch(int $batchId): ?float;

    /** Newest individual weighing date behind the current aggregate of the batch. */
    public function getLatestWeighingDateByBatch(int $batchId): ?\DateTimeInterface;

    /**
     * @param int $batchId
     * @return CaravanEntity[]
     */
    public function findGestatingByBatch(int $batchId): array;
}
