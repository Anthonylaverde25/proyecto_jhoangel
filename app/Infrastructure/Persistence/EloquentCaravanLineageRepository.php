<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;


use App\Core\Enums\WeaningType;

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
     * @param int[] $caravanIds
     * @return array<int, LineageEntity>
     */
    public function findByCaravanIds(array $caravanIds): array
    {
        if (empty($caravanIds)) {
            return [];
        }

        $lineages = [];
        CaravanLineage::with(['mother', 'father'])
            ->whereIn('caravan_id', $caravanIds)
            ->get()
            ->each(function (CaravanLineage $model) use (&$lineages) {
                $lineages[(int) $model->caravan_id] = LineageMapper::toEntity($model);
            });

        return $lineages;
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

    public function nursingPairsOf(array $caravanIds): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return DB::table('caravan_lineage')
            ->join('caravans as mothers', 'mothers.id', '=', 'caravan_lineage.mother_id')
            ->join('caravans as calves', 'calves.id', '=', 'caravan_lineage.caravan_id')
            ->where('caravan_lineage.is_nursing', true)
            ->where(fn ($q) => $q->whereIn('caravan_lineage.mother_id', $caravanIds)->orWhereIn('caravan_lineage.caravan_id', $caravanIds))
            ->get([
                'caravan_lineage.mother_id',
                'caravan_lineage.caravan_id',
                'mothers.batch_id as mother_batch_id',
                'calves.batch_id as calf_batch_id',
                'mothers.identification as mother_identification',
                'calves.identification as calf_identification',
                'calves.sex as calf_sex',
            ])
            ->map(fn ($row) => [
                'mother_id' => (int) $row->mother_id,
                'calf_id' => (int) $row->caravan_id,
                'mother_batch_id' => $row->mother_batch_id !== null ? (int) $row->mother_batch_id : null,
                'calf_batch_id' => $row->calf_batch_id !== null ? (int) $row->calf_batch_id : null,
                'mother_identification' => (string) $row->mother_identification,
                'calf_identification' => $row->calf_identification !== null ? (string) $row->calf_identification : null,
            ])
            ->values()
            ->all();
    }

    public function wean(int $caravanId, string $date, ?WeaningType $type): void
    {
        CaravanLineage::where('caravan_id', $caravanId)->update([
            'is_nursing' => false,
            'nursing_ended_at' => $date,
            'nursing_end_reason' => LineageEntity::ENDED_WEANED,
            'weaning_type' => $type?->value,
        ]);
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
