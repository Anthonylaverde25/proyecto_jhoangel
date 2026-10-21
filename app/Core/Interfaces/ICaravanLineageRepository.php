<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\LineageEntity;

interface ICaravanLineageRepository
{
    public function save(LineageEntity $lineage): LineageEntity;

    public function findByCaravanId(int $caravanId): ?LineageEntity;

    /**
     * Lineage records of several calves in one query, indexed by caravan_id.
     *
     * @param int[] $caravanIds
     * @return array<int, LineageEntity>
     */
    public function findByCaravanIds(array $caravanIds): array;

    /**
     * @param int $motherId
     * @return LineageEntity[]
     */
    public function findOffspringByMotherId(int $motherId): array;

    /**
     * The mother–calf pairs still at foot in which any animal of the list takes part, with the batch
     * each one is in now.
     *
     * @param int[] $caravanIds
     * @return list<array{mother_id: int, calf_id: int, mother_batch_id: ?int, calf_batch_id: ?int, mother_identification: string, calf_identification: ?string}>
     */
    public function nursingPairsOf(array $caravanIds): array;

    /**
     * Ends the time at foot of a calf as a weaning, on that day and of that kind.
     */
    public function wean(int $caravanId, string $date, ?\App\Core\Enums\WeaningType $type): void;

    /**
     * Returns all lineage records where father_id is NULL (pending sire assignment).
     *
     * @return LineageEntity[]
     */
    public function findPendingSire(): array;

    /**
     * Assigns a father to a calf's lineage record and records the identification method and notes.
     * Sets sire_assigned_at to the current timestamp.
     */
    public function assignSire(
        int $caravanId,
        int $fatherId,
        string $identificationMethod,
        ?string $sireNotes
    ): LineageEntity;
}
