<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\WeighingOrderMapper;
use App\Core\Entities\WeighingOrderEntity;
use App\Core\Enums\BatchWeighingEntry;
use App\Core\Interfaces\IWeighingOrderRepository;
use App\Models\WeighingOrder;
use App\Models\WeighingOrderAnimal;
use App\Models\WeighingOrderGroup;
use App\Models\WeighingOrderHistory;
use App\Models\WeighingType;

class EloquentWeighingOrderRepository implements IWeighingOrderRepository
{
    private const RELATIONS = ['weighingType', 'batch', 'groups', 'animals.caravan'];

    public function create(WeighingOrderEntity $order, ?int $userId): WeighingOrderEntity
    {
        $measure = $order->getMeasure();
        $declared = $measure?->entry === BatchWeighingEntry::AVERAGE;

        $model = WeighingOrder::create([
            'company_id' => $order->getCompanyId(),
            'code' => $order->getCode(),
            'status' => $order->getStatus()->value,
            'kind' => $order->getKind()->value,
            'mode' => $order->getMode()->value,
            'weighing_type_id' => WeighingType::where('code', $order->getType()->value)->value('id'),
            'batch_id' => $order->getBatchId(),
            'batch_entry' => $measure?->entry->value,
            'declared_head_count' => $declared ? $measure->headCount : null,
            'declared_average_weight' => $declared ? round($measure->averageWeight(), 2) : null,
            'shrunk_condition' => $order->getShrunkCondition()->value,
            'shrink_pct' => $order->getShrinkPct(),
            'weighing_date' => $order->getWeighingDate(),
            'weighing_time' => $order->getWeighingTime(),
            'planned_head_count' => $order->getPlannedHeadCount(),
            'requested_by_user_id' => $order->getRequestedByUserId(),
            'emitted_at' => $order->getEmittedAt(),
            'first_executed_at' => $order->getFirstExecutedAt(),
            'closed_at' => $order->getClosedAt(),
            'responsable' => $order->getResponsable(),
            'observations' => $order->getObservations(),
        ]);

        foreach ($measure?->loads ?? [] as $group) {
            WeighingOrderGroup::create([
                'company_id' => $order->getCompanyId(),
                'weighing_order_id' => $model->id,
                'sequence' => $group->sequence,
                'head_count' => $group->headCount,
                'gross_weight' => $group->grossWeight,
                'tare_weight' => $group->tareWeight,
                'weighed_at' => $order->getFirstExecutedAt(),
            ]);
        }

        foreach ($order->getAnimals() as $animal) {
            WeighingOrderAnimal::create([
                'company_id' => $order->getCompanyId(),
                'weighing_order_id' => $model->id,
                'caravan_id' => $animal->getCaravanId(),
                'source_batch_id' => $animal->getSourceBatchId(),
                'status' => $animal->getStatus(),
                'caravan_weight_id' => $animal->getCaravanWeightId(),
                'weighed_at' => $animal->getWeighedAt(),
            ]);
        }

        WeighingOrderHistory::create([
            'company_id' => $order->getCompanyId(),
            'weighing_order_id' => $model->id,
            'from_status' => null,
            'to_status' => $order->getStatus()->value,
            'user_id' => $userId,
            'notes' => $order->getKind()->value === 'REGISTERED' ? 'Pesaje registrado' : 'Orden creada',
        ]);

        $order->assignId((int) $model->id);

        return $order;
    }

    public function saveAnimalLines(WeighingOrderEntity $order): void
    {
        foreach ($order->getAnimals() as $animal) {
            WeighingOrderAnimal::where('weighing_order_id', $order->getId())
                ->where('caravan_id', $animal->getCaravanId())
                ->update([
                    'status' => $animal->getStatus(),
                    'caravan_weight_id' => $animal->getCaravanWeightId(),
                    'weighed_at' => $animal->getWeighedAt(),
                ]);
        }
    }

    public function findById(int $id, int $companyId): ?WeighingOrderEntity
    {
        $model = WeighingOrder::with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->find($id);

        return $model ? WeighingOrderMapper::toEntity($model) : null;
    }

    public function findByBatch(int $batchId, int $companyId): array
    {
        return WeighingOrder::with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->where('batch_id', $batchId)
            ->orderByDesc('weighing_date')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (WeighingOrder $m) => WeighingOrderMapper::toEntity($m))
            ->all();
    }

    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string
    {
        return WeighingOrder::where('company_id', $companyId)
            ->where('code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');
    }
}
