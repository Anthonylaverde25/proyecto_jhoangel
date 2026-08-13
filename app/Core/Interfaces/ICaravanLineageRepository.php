<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\LineageEntity;

interface ICaravanLineageRepository
{
    public function save(LineageEntity $lineage): LineageEntity;

    public function findByCaravanId(int $caravanId): ?LineageEntity;

    /**
     * @param int $motherId
     * @return LineageEntity[]
     */
    public function findOffspringByMotherId(int $motherId): array;

    public function wean(int $caravanId): void;

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
