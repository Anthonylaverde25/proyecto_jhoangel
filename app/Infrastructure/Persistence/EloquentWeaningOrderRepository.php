<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\WeaningOrderMapper;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Enums\WeaningOrderAnimalStatus;
use App\Core\Interfaces\IWeaningOrderRepository;
use App\Models\WeaningOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentWeaningOrderRepository implements IWeaningOrderRepository
{
    private const DETAIL_RELATIONS = [
        'destinationActivity',
        'requestedByUser',
        'destinations.targetBatch',
        'destinations.resolvedBatch',
        'animals.sourceBatch',
        'animals.caravan.categoryRelation',
        'animals.caravan.subcategoryRelation',
        'animals.caravan.lineage.mother',
        'animals.targetCategory',
        'animals.targetSubcategory',
        'history.actionUser',
    ];

    public function save(WeaningOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): WeaningOrderEntity
    {
        $id = DB::transaction(function () use ($order, $actionUserId, $reason, $metadata): int {
            $isNew = $order->getId() === null;
            $model = $isNew
                ? new WeaningOrder()
                : WeaningOrder::where('company_id', $order->getCompanyId())->findOrFail($order->getId());
            $oldStatus = $isNew ? null : $model->status;

            $model->fill([
                'company_id' => $order->getCompanyId(),
                'code' => $order->getCode(),
                'status' => $order->getStatus()->value,
                'kind' => $order->getKind()->value,
                'destination_mode' => $order->getDestinationMode(),
                'category_mode' => $order->getCategoryMode()->value,
                'destination_activity_id' => $order->getDestinationActivityId(),
                'weaning_type' => $order->getWeaningType()?->value,
                'planned_head_count' => $order->getPlannedHeadCount(),
                'weaning_date' => $order->getWeaningDate(),
                'requested_by_user_id' => $order->getRequestedByUserId(),
                'emitted_at' => $order->getEmittedAt(),
                'printed_at' => $order->getPrintedAt(),
                'first_executed_at' => $order->getFirstExecutedAt(),
                'closed_at' => $order->getClosedAt(),
                'responsable' => $order->getResponsable(),
                'observations' => $order->getObservations(),
                'closing_reason' => $order->getClosingReason(),
            ]);
            $model->save();

            // A draft that was rewritten gets its roster replaced whole: it committed nothing.
            $rewrite = $isNew || $order->isRosterReplaced();

            if (!$isNew && $rewrite) {
                $model->animals()->delete();
                $model->destinations()->delete();
            }

            $destinationIdByKey = $this->syncDestinations($model, $order, $rewrite);
            $this->syncAnimals($model, $order, $rewrite, $destinationIdByKey);

            if ($isNew || $oldStatus !== $model->status || $metadata !== null) {
                $model->history()->create([
                    'company_id' => $model->company_id,
                    'from_status' => $oldStatus,
                    'to_status' => $model->status,
                    'action_user_id' => $actionUserId,
                    'action_reason' => $reason,
                    'action_metadata' => $metadata,
                ]);
            }

            return (int) $model->id;
        });

        return $this->findById($id, $order->getCompanyId())
            ?? throw new \RuntimeException('The weaning order could not be read back after saving.');
    }

    public function findById(int $id, int $companyId): ?WeaningOrderEntity
    {
        $model = WeaningOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->find($id);

        return $model !== null ? WeaningOrderMapper::toEntity($model) : null;
    }

    public function findByCode(string $code, int $companyId): ?WeaningOrderEntity
    {
        $model = WeaningOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        return $model !== null ? WeaningOrderMapper::toEntity($model) : null;
    }

    public function list(int $companyId, ?string $status = null, ?string $kind = null): array
    {
        // The roll is loaded with its source batch but without the calves: the list needs how many
        // were weaned and from where, not who.
        return WeaningOrder::with([
            'destinationActivity',
            'requestedByUser',
            'destinations.targetBatch',
            'destinations.resolvedBatch',
            'animals.sourceBatch',
        ])
            ->where('company_id', $companyId)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->orderByDesc('id')
            ->get()
            ->map(fn (WeaningOrder $model) => WeaningOrderMapper::toEntity($model))
            ->all();
    }

    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string
    {
        $code = WeaningOrder::where('company_id', $companyId)
            ->where('code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        return $code !== null ? (string) $code : null;
    }

    public function findCommittedCaravans(array $caravanIds, int $companyId): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return DB::table('weaning_order_animals')
            ->join('weaning_orders', 'weaning_order_animals.weaning_order_id', '=', 'weaning_orders.id')
            ->where('weaning_orders.company_id', $companyId)
            ->whereIn('weaning_orders.status', [TransferOrderStatus::ISSUED->value, TransferOrderStatus::PARTIAL->value])
            ->where('weaning_order_animals.status', WeaningOrderAnimalStatus::PENDING->value)
            ->whereIn('weaning_order_animals.caravan_id', $caravanIds)
            ->pluck('weaning_orders.code', 'weaning_order_animals.caravan_id')
            ->mapWithKeys(fn ($code, $caravanId) => [(int) $caravanId => (string) $code])
            ->all();
    }

    public function calfFacts(array $caravanIds, int $companyId): array
    {
        if ($caravanIds === []) {
            return [];
        }

        $facts = [];

        DB::table('caravans')
            ->leftJoin('caravan_lineage', 'caravan_lineage.caravan_id', '=', 'caravans.id')
            ->where('caravans.company_id', $companyId)
            ->whereIn('caravans.id', $caravanIds)
            ->get([
                'caravans.id',
                'caravans.identification',
                'caravans.batch_id',
                'caravans.sex',
                'caravan_lineage.is_nursing',
                'caravan_lineage.birth_date',
            ])
            ->each(function ($row) use (&$facts): void {
                $facts[(int) $row->id] = [
                    'identification' => (string) $row->identification,
                    'batch_id' => $row->batch_id !== null ? (int) $row->batch_id : null,
                    'sex' => (string) $row->sex,
                    'is_nursing' => $row->is_nursing !== null ? (bool) $row->is_nursing : null,
                    'birth_date' => $row->birth_date !== null ? substr((string) $row->birth_date, 0, 10) : null,
                ];
            });

        return $facts;
    }

    /**
     * Destinations are written whole once; afterwards only the batch each one resolved to changes.
     *
     * @return array<string, int> destination key => row id
     */
    private function syncDestinations(WeaningOrder $model, WeaningOrderEntity $order, bool $isNew): array
    {
        $idByKey = [];

        if ($isNew) {
            foreach ($order->getDestinations() as $destination) {
                $row = $model->destinations()->create([
                    'company_id' => $model->company_id,
                    'destination_key' => $destination->getKey(),
                    'label' => $destination->getLabel(),
                    'target_batch_id' => $destination->getTargetBatchId(),
                    'new_batch_name' => $destination->getNewBatchName(),
                    'is_confined' => $destination->isConfined(),
                    'resolved_batch_id' => $destination->getResolvedBatchId(),
                ]);

                $idByKey[$destination->getKey()] = (int) $row->id;
            }

            return $idByKey;
        }

        foreach ($order->getDestinations() as $destination) {
            if ($destination->getId() !== null && $destination->getResolvedBatchId() !== null) {
                $model->destinations()
                    ->whereKey($destination->getId())
                    ->whereNull('resolved_batch_id')
                    ->update(['resolved_batch_id' => $destination->getResolvedBatchId()]);
            }
        }

        return $idByKey;
    }

    /**
     * @param array<string, int> $destinationIdByKey
     */
    private function syncAnimals(WeaningOrder $model, WeaningOrderEntity $order, bool $isNew, array $destinationIdByKey): void
    {
        if ($isNew) {
            $now = now();
            $rows = [];

            foreach ($order->getAnimals() as $animal) {
                $rows[] = [
                    'company_id' => $model->company_id,
                    'weaning_order_id' => $model->id,
                    'caravan_id' => $animal->getCaravanId(),
                    'source_batch_id' => $animal->getSourceBatchId(),
                    'weaning_order_destination_id' => $animal->getDestinationKey() !== null
                        ? ($destinationIdByKey[$animal->getDestinationKey()] ?? null)
                        : null,
                    'target_category_id' => $animal->getTargetCategoryId(),
                    'target_subcategory_id' => $animal->getTargetSubcategoryId(),
                    'status' => $animal->getStatus()->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('weaning_order_animals')->insert($chunk);
            }

            return;
        }

        $current = $model->animals()->get()->keyBy('caravan_id');

        foreach ($order->getAnimals() as $animal) {
            $row = $current->get($animal->getCaravanId());

            if ($row === null || $row->status === $animal->getStatus()->value) {
                continue;
            }

            $row->update([
                'status' => $animal->getStatus()->value,
                'weaned_at' => $animal->getWeanedAt(),
                'caravan_movement_id' => $animal->getCaravanMovementId(),
                // The C/S decided at the chute, when the order left it for then.
                'target_category_id' => $animal->getTargetCategoryId(),
                'target_subcategory_id' => $animal->getTargetSubcategoryId(),
            ]);
        }
    }
}
