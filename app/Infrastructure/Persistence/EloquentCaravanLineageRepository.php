<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\LineageEntity;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Exceptions\DomainException;
use App\Models\CaravanLineage;
use App\Application\Mappers\LineageMapper;

class EloquentCaravanLineageRepository implements ICaravanLineageRepository
{
    public function save(LineageEntity $lineage): LineageEntity
    {
        $model = $lineage->getId() !== null ? CaravanLineage::find($lineage->getId()) : null;
        $model = LineageMapper::toModel($lineage, $model);
        $model->save();

        return LineageMapper::toEntity($model->load(['mother', 'father']));
    }

    public function findByCaravanId(int $caravanId): ?LineageEntity
    {
        $model = CaravanLineage::with(['mother', 'father'])
            ->where('caravan_id', $caravanId)
            ->first();

        return $model ? LineageMapper::toEntity($model) : null;
    }

    /**
     * @param int $motherId
     * @return LineageEntity[]
     */
    public function findOffspringByMotherId(int $motherId): array
    {
        $models = CaravanLineage::with(['mother', 'father'])
            ->where('mother_id', $motherId)
            ->get();

        return $models->map(fn($model) => LineageMapper::toEntity($model))->toArray();
    }

    public function wean(int $caravanId): void
    {
        CaravanLineage::where('caravan_id', $caravanId)->update(['is_nursing' => false]);
    }

    /**
     * Returns all lineage records where father_id is NULL.
     * Eagerly loads caravan (calf), mother, and gestation for resource transformation.
     *
     * @return LineageEntity[]
     */
    public function findPendingSire(): array
    {
        $models = CaravanLineage::with(['caravan', 'mother', 'father', 'gestation'])
            ->whereNull('father_id')
            ->orderBy('birth_date', 'asc')
            ->get();

        return $models->map(fn($model) => LineageMapper::toEntity($model))->toArray();
    }

    /**
     * Assigns a sire to a calf's lineage record and stamps the assignment timestamp.
     */
    public function assignSire(
        int $caravanId,
        int $fatherId,
        string $identificationMethod,
        ?string $sireNotes
    ): LineageEntity {
        $model = CaravanLineage::with(['mother', 'father'])
            ->where('caravan_id', $caravanId)
            ->first();

        if ($model === null) {
            throw new DomainException("No lineage record found for calf ID {$caravanId}.");
        }

        if ($model->father_id !== null) {
            throw new DomainException("Calf ID {$caravanId} already has a sire assigned.");
        }

        $model->father_id                  = $fatherId;
        $model->sire_assigned_at           = now();
        $model->sire_identification_method = $identificationMethod;
        $model->sire_notes                 = $sireNotes;
        $model->save();

        return LineageMapper::toEntity($model->load(['mother', 'father']));
    }
}
