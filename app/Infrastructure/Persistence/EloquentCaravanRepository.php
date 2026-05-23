<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;
use App\Models\Caravan;
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
            $model->gestations()->updateOrCreate(
                ['id' => $gestation->getId()],
                [
                    'start_date' => $gestation->getStartDate(),
                    'estimated_due_date' => $gestation->getEstimatedDueDate(),
                    'is_current' => $gestation->isCurrent(),
                    'gestation_stage' => $gestation->getGestationStage()->value,
                    'gestation_months' => $gestation->getGestationMonths(),
                    'result' => $gestation->getResult()?->value,
                    'end_date' => $gestation->getEndDate(),
                    'notes' => $gestation->getNotes(),
                ]
            );
        }

        return CaravanMapper::toEntity($model->load(['breedRelation', 'currentWeight', 'femaleDetail', 'gestations']));
    }

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::with(['breedRelation', 'currentWeight', 'femaleDetail', 'gestations'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findByIdentificationGlobal(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::withoutGlobalScopes()
            ->with(['breedRelation', 'currentWeight', 'femaleDetail', 'gestations'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findById(int $id): ?CaravanEntity
    {
        $model = Caravan::with(['breedRelation', 'currentWeight', 'femaleDetail', 'gestations'])->find($id);
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findAll(): array
    {
        $models = Caravan::with(['breedRelation', 'batch', 'currentWeight', 'femaleDetail', 'gestations'])->get();
        return $models->map(fn($model) => CaravanMapper::toEntity($model))->toArray();
    }

    public function delete(int $id): bool
    {
        return (bool) Caravan::destroy($id);
    }

    public function countByBatch(int $batchId): int
    {
        return Caravan::where('batch_id', $batchId)->count();
    }

    public function getAverageWeightByBatch(int $batchId): ?float
    {
        $avg = Caravan::where('batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->avg('caravan_weights.weight');

        return $avg !== null ? (float) $avg : null;
    }
}
