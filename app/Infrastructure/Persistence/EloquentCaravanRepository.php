<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;
use App\Models\Caravan;
use Illuminate\Support\Facades\DB;
use App\Application\Mappers\CaravanMapper;

class EloquentCaravanRepository implements ICaravanRepository
{
    public function save(CaravanEntity $caravan): CaravanEntity
    {
        $model = $caravan->getId() !== null ? Caravan::find($caravan->getId()) : null;
        $model = CaravanMapper::toModel($caravan, $model);
        $model->save();

        if ($caravan->getReproductiveDetails() !== null) {
            $details = $caravan->getReproductiveDetails();
            $model->femaleDetail()->updateOrCreate(
                ['caravan_id' => $model->id],
                [
                    'is_empty' => $details->isEmpty(),
                    'arrival_category' => $details->getArrivalCategory(),
                ]
            );
        } else {
            $model->femaleDetail()->delete();
        }

        // Persistir Gestaciones
        foreach ($caravan->getGestations() as $gestation) {
            $gestationModel = $model->gestations()->updateOrCreate(
                ['id' => $gestation->getId()],
                [
                    'start_date' => $gestation->getStartDate(),
                    'estimated_due_date' => $gestation->getEstimatedDueDate(),
                    'is_current' => $gestation->isCurrent(),
                    'gestation_stage' => $gestation->getGestationStage()->value,
                    'gestation_months' => $gestation->getGestationMonths(),
                    'success' => $gestation->getSuccess(),
                    'loss_reason_id' => $gestation->getLossReasonId(),
                    'loss_notes' => $gestation->getLossNotes(),
                    'end_date' => $gestation->getEndDate(),
                    'notes' => $gestation->getNotes(),
                    'service_order_id' => $gestation->getServiceOrderId(),
                    'calving_overdue_reported_at' => $gestation->getCalvingOverdueReportedAt(),
                ]
            );

            // Sincronizar sires
            $sireSyncData = [];
            foreach ($gestation->getSires() as $sire) {
                $sireSyncData[$sire->getSireId()] = ['is_confirmed' => $sire->isConfirmed()];
            }
            $gestationModel->sires()->sync($sireSyncData);
        }

        return CaravanMapper::toEntity($model->load(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father']));
    }

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findByIdentifications(array $identifications): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn ($raw) => trim((string) $raw), $identifications),
            static fn (string $value): bool => $value !== ''
        )));

        if ($values === []) {
            return [];
        }

        $models = Caravan::with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father'])
            ->whereIn('identification', $values)
            ->get();

        $resolved = [];

        foreach ($models as $model) {
            $resolved[mb_strtoupper(trim((string) $model->identification))] = CaravanMapper::toEntity($model);
        }

        return $resolved;
    }

    public function findOwnershipByIdentifications(array $identifications): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn ($raw) => trim((string) $raw), $identifications),
            static fn (string $value): bool => $value !== ''
        )));

        if ($values === []) {
            return [];
        }

        $rows = Caravan::withoutGlobalScopes()
            ->leftJoin('batches', 'batches.id', '=', 'caravans.batch_id')
            ->whereIn('caravans.identification', $values)
            ->get([
                'caravans.id',
                'caravans.identification',
                'caravans.company_id',
                'caravans.batch_id',
                'batches.name as batch_name',
            ]);

        $resolved = [];

        foreach ($rows as $row) {
            $identification = (string) $row->identification;
            $resolved[$identification] = new \App\Core\ValueObjects\CaravanOwnership(
                (int) $row->id,
                $identification,
                (int) $row->company_id,
                $row->batch_id !== null ? (int) $row->batch_id : null,
                $row->batch_name !== null ? (string) $row->batch_name : null,
            );
        }

        return $resolved;
    }

    public function findByIdentificationGlobal(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::withoutGlobalScopes()
            ->with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findIdsInExternalBatches(array $caravanIds): array
    {
        if (empty($caravanIds)) {
            return [];
        }

        return Caravan::whereIn('id', $caravanIds)
            ->whereHas('batch.farm', fn ($farmQb) => $farmQb->whereNotNull('provider_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findById(int $id): ?CaravanEntity
    {
        $model = Caravan::with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'batch.farm.provider', 'provider', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father'])->find($id);
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function countOwn(): int
    {
        $query = Caravan::query();
        $this->applyOwnScope($query);

        return $query->count();
    }

    /**
     * Own animals: no batch, or a batch that is not in a provider's farm.
     */
    private function applyOwnScope(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($q) {
            $q->whereNull('batch_id')
              ->orWhereHas('batch', function ($qb) {
                  $qb->where(function ($subQb) {
                      $subQb->whereNull('farm_id')
                            ->orWhereHas('farm', function ($farmQb) {
                                $farmQb->whereNull('provider_id');
                            });
                  });
              });
        });
    }

    public function findAll(?string $scope = 'own'): array
    {
        $query = Caravan::with([
            'categoryRelation',
            'subcategoryRelation',
            'breedRelation',
            'colorRelation',
            'batch.farm.provider',
            'currentWeight',
            'femaleDetail',
            'gestations.sires', 'gestations.lossReason',
            'lineage.mother',
            'lineage.father',
            'provider',
        ]);

        if ($scope === 'own') {
            $this->applyOwnScope($query);
        } elseif ($scope === 'external') {
            $query->where(function ($q) {
                $q->whereNotNull('provider_id')
                  ->orWhereHas('batch.farm', function ($farmQb) {
                      $farmQb->whereNotNull('provider_id');
                  });
            });
        }

        return $query->get()->map(fn($model) => CaravanMapper::toEntity($model))->toArray();
    }


    public function delete(int $id): bool
    {
        return (bool) Caravan::destroy($id);
    }

    public function countByBatch(int $batchId): int
    {
        return Caravan::inPossession()->where('caravans.batch_id', $batchId)->count();
    }

    public function countWeighedByBatch(int $batchId): int
    {
        return Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->count();
    }

    public function getTotalWeightByBatch(int $batchId): ?float
    {
        $sum = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->sum('caravan_weights.weight');

        // `sum` returns 0 for an empty set, which would state that the batch holds zero
        // kilos of cattle. That is true for an empty batch and meaningless otherwise, so
        // the caller decides by looking at the weighed count.
        return $sum !== null ? (float) $sum : null;
    }

    public function getLatestWeighingDateByBatch(int $batchId): ?\DateTimeInterface
    {
        $date = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->max('caravan_weights.weighing_date');

        return $date !== null ? new \DateTimeImmutable((string) $date) : null;
    }

    public function moveCaravansToBatch(
        array $caravanIds,
        int $targetBatchId,
        ?int $categoryId = null,
        ?int $subcategoryId = null
    ): array {
        if ($caravanIds === []) {
            return [];
        }

        // The source batches have to be read BEFORE the move: afterwards the previous
        // membership is gone and there is no way to know which batches to recalculate.
        $sourceBatchIds = Caravan::whereIn('id', $caravanIds)
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $data = ['batch_id' => $targetBatchId];

        if ($categoryId !== null) {
            $data['category_id'] = $categoryId;
        }

        if ($subcategoryId !== null) {
            $data['subcategory_id'] = $subcategoryId;
        }

        Caravan::whereIn('id', $caravanIds)->update($data);

        return array_values(array_unique([...$sourceBatchIds, $targetBatchId]));
    }

    public function getAverageWeightByBatch(int $batchId): ?float
    {
        $avg = Caravan::where('batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->avg('caravan_weights.weight');

        return $avg !== null ? (float) $avg : null;
    }

    public function getMinWeightByBatch(int $batchId): ?float
    {
        $min = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->min('caravan_weights.weight');

        return $min !== null ? (float) $min : null;
    }

    public function getMaxWeightByBatch(int $batchId): ?float
    {
        $max = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->max('caravan_weights.weight');

        return $max !== null ? (float) $max : null;
    }

    public function findBirthHistory(): array
    {
        $gestations = \App\Models\CaravanGestation::where('success', true)
            ->with(['caravan.batch', 'offspring.caravan.batch'])
            ->get();

        $history = [];
        foreach ($gestations as $g) {
            foreach ($g->offspring as $lineage) {
                if ($lineage->caravan === null) {
                    continue;
                }

                $history[] = new \App\Core\Entities\BirthHistoryEntity(
                    gestationId: (int) $g->id,
                    motherId: (int) $g->caravan_id,
                    motherIdentification: (string) ($g->caravan?->identification ?? ''),
                    birthDate: $g->end_date ? $g->end_date->format('Y-m-d') : '',
                    notes: $g->notes,
                    calfId: (int) $lineage->caravan_id,
                    calfIdentification: (string) ($lineage->caravan?->identification ?? ''),
                    isNursing: (bool) $lineage->is_nursing,
                    calfSex: $lineage->caravan?->sex?->value,
                    calfBatchName: $lineage->caravan?->batch?->name,
                    calfBatchId: $lineage->caravan?->batch_id !== null ? (int) $lineage->caravan->batch_id : null,
                    motherBatchId: $g->caravan?->batch_id !== null ? (int) $g->caravan->batch_id : null,
                    motherBatchName: $g->caravan?->batch?->name,
                    calfCategoryId: $lineage->caravan?->category_id !== null ? (int) $lineage->caravan->category_id : null,
                    calfSubcategoryId: $lineage->caravan?->subcategory_id !== null ? (int) $lineage->caravan->subcategory_id : null
                );
            }
        }

        return $history;
    }

    public function updateBatchAndCategory(int $caravanId, int $batchId, ?int $categoryId = null, ?int $subcategoryId = null): void
    {
        $data = ['batch_id' => $batchId];
        if ($categoryId !== null) {
            $data['category_id'] = $categoryId;
        }
        if ($subcategoryId !== null) {
            $data['subcategory_id'] = $subcategoryId;
        }
        Caravan::where('id', $caravanId)->update($data);
    }

    public function updateBatchAndReclassify(int $caravanId, int $batchId, int $categoryId, ?int $subcategoryId = null): void
    {
        Caravan::where('id', $caravanId)->update([
            'batch_id' => $batchId,
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
        ]);
    }

    public function updateTeeth(int $caravanId, int $teeth): void
    {
        Caravan::where('id', $caravanId)->update(['teeth' => $teeth]);
    }

    public function findGestatingByBatch(int $batchId): array
    {
        $models = Caravan::with(['breedRelation', 'batch', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father'])
            ->where('batch_id', $batchId)
            ->whereHas('gestations', function ($query) {
                $query->where('is_current', true);
            })
            ->get();
            
        return $models->map(fn($model) => CaravanMapper::toEntity($model))->toArray();
    }
}

