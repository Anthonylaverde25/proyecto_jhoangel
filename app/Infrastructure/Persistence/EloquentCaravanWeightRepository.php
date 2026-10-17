<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CaravanWeightEntity;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Models\CaravanWeight;
use App\Models\WeighingType;
use App\Application\Mappers\CaravanWeightMapper;

class EloquentCaravanWeightRepository implements ICaravanWeightRepository
{
    public function save(CaravanWeightEntity $weight): CaravanWeightEntity
    {
        $model = $weight->getId() !== null ? CaravanWeight::find($weight->getId()) : null;
        $model = CaravanWeightMapper::toModel($weight, $this->typeId($weight->getType()), $model);
        $model->save();
        $model->load('weighingType');

        return CaravanWeightMapper::toEntity($model);
    }

    public function findCurrentByCaravanId(int $caravanId): ?CaravanWeightEntity
    {
        $model = CaravanWeight::with('weighingType')
            ->where('caravan_id', $caravanId)
            ->where('current', true)
            ->first();

        return $model ? CaravanWeightMapper::toEntity($model) : null;
    }

    public function findByCaravanId(int $caravanId): array
    {
        $models = CaravanWeight::with('weighingType')
            ->where('caravan_id', $caravanId)
            ->orderBy('weighing_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $models->map(fn($model) => CaravanWeightMapper::toEntity($model))->toArray();
    }

    public function markAllNonCurrentForCaravan(int $caravanId): void
    {
        CaravanWeight::where('caravan_id', $caravanId)
            ->update(['current' => false]);
    }

    public function hasWeighingAfter(int $caravanId, \DateTimeInterface $date): bool
    {
        return CaravanWeight::where('caravan_id', $caravanId)
            ->whereDate('weighing_date', '>', $date->format('Y-m-d'))
            ->exists();
    }

    /**
     * The catalog is seeded by migration, so a missing code is a broken database, not user input.
     */
    private function typeId(WeighingTypeCode $code): int
    {
        $id = WeighingType::where('code', $code->value)->value('id');

        if ($id === null) {
            throw new \RuntimeException("Weighing type {$code->value} is missing from the catalog.");
        }

        return (int) $id;
    }
}
