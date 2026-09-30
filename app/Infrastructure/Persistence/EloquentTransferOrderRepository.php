<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\TransferOrderMapper;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Enums\TransferOrderAnimalStatus;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Interfaces\ITransferOrderRepository;
use App\Models\TransferOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentTransferOrderRepository implements ITransferOrderRepository
{
    private const DETAIL_RELATIONS = [
        'sourceBatch.activity',
        'destinationActivity',
        'requestedByUser',
        'destinations.targetBatch',
        'destinations.resolvedBatch',
        'destinations.newBatchType',
        'animals.caravan.categoryRelation',
        'animals.caravan.subcategoryRelation',
        'animals.targetCategory',
        'animals.targetSubcategory',
        'history.actionUser',
    ];

    public function save(TransferOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): TransferOrderEntity
    {
        $id = DB::transaction(function () use ($order, $actionUserId, $reason, $metadata): int {
            $isNew = $order->getId() === null;
            $model = $isNew
                ? new TransferOrder()
                : TransferOrder::where('company_id', $order->getCompanyId())->findOrFail($order->getId());
            $oldStatus = $isNew ? null : $model->status;

            $model->fill([
                'company_id' => $order->getCompanyId(),
                'source_batch_id' => $order->getSourceBatchId(),
                'destination_activity_id' => $order->getDestinationActivityId(),
                'code' => $order->getCode(),
                'status' => $order->getStatus()->value,
                'kind' => $order->getKind()->value,
                'destination_mode' => $order->getDestinationMode(),
                'category_mode' => $order->getCategoryMode()->value,
                'planned_head_count' => $order->getPlannedHeadCount(),
                'movement_date' => $order->getMovementDate(),
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

            // A draft that was rewritten gets its roster replaced whole: it committed nothing, so
            // there is no history in its lines to preserve.
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
            ?? throw new \RuntimeException('The transfer order could not be read back after saving.');
    }

    public function findById(int $id, int $companyId): ?TransferOrderEntity
    {
        $model = TransferOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->find($id);

        return $model !== null ? TransferOrderMapper::toEntity($model) : null;
    }

    public function findByCode(string $code, int $companyId): ?TransferOrderEntity
    {
        $model = TransferOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        return $model !== null ? TransferOrderMapper::toEntity($model) : null;
    }

    public function list(int $companyId, ?string $status = null, ?int $sourceBatchId = null, ?string $kind = null): array
    {
        // The roll is loaded without the caravans: the list needs how many moved, not who.
        return TransferOrder::with([
            'sourceBatch.activity',
            'destinationActivity',
            'requestedByUser',
            'destinations.targetBatch',
            'destinations.resolvedBatch',
            'destinations.newBatchType',
            'animals',
        ])
            ->where('company_id', $companyId)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($sourceBatchId !== null, fn (Builder $query) => $query->where('source_batch_id', $sourceBatchId))
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->orderByDesc('id')
            ->get()
            ->map(fn (TransferOrder $model) => TransferOrderMapper::toEntity($model))
            ->all();
    }

    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string
    {
        $code = TransferOrder::where('company_id', $companyId)
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

        return DB::table('transfer_order_animals')
            ->join('transfer_orders', 'transfer_order_animals.transfer_order_id', '=', 'transfer_orders.id')
            ->where('transfer_orders.company_id', $companyId)
            ->whereIn('transfer_orders.status', [TransferOrderStatus::ISSUED->value, TransferOrderStatus::PARTIAL->value])
            ->where('transfer_order_animals.status', TransferOrderAnimalStatus::PENDING->value)
            ->whereIn('transfer_order_animals.caravan_id', $caravanIds)
            ->pluck('transfer_orders.code', 'transfer_order_animals.caravan_id')
            ->mapWithKeys(fn ($code, $caravanId) => [(int) $caravanId => (string) $code])
            ->all();
    }

    public function currentBatchOfCaravans(array $caravanIds, int $companyId): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return DB::table('caravans')
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->pluck('batch_id', 'id')
            ->mapWithKeys(fn ($batchId, $id) => [(int) $id => $batchId !== null ? (int) $batchId : null])
            ->all();
    }

    public function sexOfCaravans(array $caravanIds, int $companyId): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return DB::table('caravans')
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->pluck('sex', 'id')
            ->mapWithKeys(fn ($sex, $id) => [(int) $id => (string) $sex])
            ->all();
    }

    /**
     * Destinations are written whole once; afterwards only the batch each one resolved to can
     * change.
     *
     * @return array<string, int> destination key => row id
     */
    private function syncDestinations(TransferOrder $model, TransferOrderEntity $order, bool $isNew): array
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
                    'new_batch_type_id' => $destination->getNewBatchTypeId(),
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
    private function syncAnimals(TransferOrder $model, TransferOrderEntity $order, bool $isNew, array $destinationIdByKey): void
    {
        if ($isNew) {
            $now = now();
            $rows = [];

            foreach ($order->getAnimals() as $animal) {
                $rows[] = [
                    'company_id' => $model->company_id,
                    'transfer_order_id' => $model->id,
                    'caravan_id' => $animal->getCaravanId(),
                    'transfer_order_destination_id' => $animal->getDestinationKey() !== null
                        ? ($destinationIdByKey[$animal->getDestinationKey()] ?? null)
                        : null,
                    'target_category_id' => $animal->getTargetCategoryId(),
                    'target_subcategory_id' => $animal->getTargetSubcategoryId(),
                    'status' => $animal->getStatus()->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // A roll of two hundred head is one insert, not two hundred.
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('transfer_order_animals')->insert($chunk);
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
                'moved_at' => $animal->getMovedAt(),
                'caravan_movement_id' => $animal->getCaravanMovementId(),
            ]);
        }
    }
}
