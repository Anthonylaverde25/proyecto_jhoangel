<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\ServiceOrderMapper;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\DB;

class EloquentServiceOrderRepository implements IServiceOrderRepository
{
    public function save(ServiceOrderEntity $entity, int $actionUserId, ?string $reason = null): ServiceOrderEntity
    {
        return DB::transaction(function () use ($entity, $actionUserId, $reason) {
            $isNew = $entity->getId() === null;
            $oldStatus = null;

            if (!$isNew) {
                $model = ServiceOrder::find($entity->getId());
                if ($model !== null) {
                    $oldStatus = $model->status;
                }
            } else {
                $model = new ServiceOrder();
            }

            $model = ServiceOrderMapper::toModel($entity, $model);
            $model->save();

            // Sync pivote tables with company_id
            $malePivotData = [];
            foreach ($entity->getMaleCaravanIds() as $maleId) {
                $malePivotData[$maleId] = ['company_id' => $model->company_id];
            }
            $model->males()->sync($malePivotData);

            $femalePivotData = [];
            foreach ($entity->getFemaleCaravanIds() as $femaleId) {
                $femalePivotData[$femaleId] = ['company_id' => $model->company_id];
            }
            $model->females()->sync($femalePivotData);

            // Check if status changed or it is a new record to log history
            if ($isNew || $oldStatus !== $model->status) {
                $model->history()->create([
                    'company_id' => $model->company_id,
                    'from_status' => $oldStatus,
                    'to_status' => $model->status,
                    'action_user_id' => $actionUserId,
                    'action_reason' => $reason,
                ]);
            }

            // Load relations to build the entity correctly
            $model->load(['males', 'females', 'history']);

            return ServiceOrderMapper::toEntity($model);
        });
    }

    public function findById(int $id, int $companyId): ?ServiceOrderEntity
    {
        $model = ServiceOrder::with(['males', 'females', 'history'])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return $model ? ServiceOrderMapper::toEntity($model) : null;
    }

    public function findActiveOrdersByCaravans(array $caravanIds, int $companyId): array
    {
        if (empty($caravanIds)) {
            return [];
        }

        $activeStatuses = [
            ServiceOrderStatus::DRAFT->value,
            ServiceOrderStatus::APPROVED->value,
        ];

        // Search caravans assigned as males in active service orders
        $maleConflicts = DB::table('service_order_males')
            ->join('service_orders', 'service_order_males.service_order_id', '=', 'service_orders.id')
            ->where('service_orders.company_id', $companyId)
            ->whereIn('service_orders.status', $activeStatuses)
            ->whereIn('service_order_males.male_caravan_id', $caravanIds)
            ->pluck('service_order_males.male_caravan_id')
            ->toArray();

        // Search caravans assigned as females in active service orders
        $femaleConflicts = DB::table('service_order_females')
            ->join('service_orders', 'service_order_females.service_order_id', '=', 'service_orders.id')
            ->where('service_orders.company_id', $companyId)
            ->whereIn('service_orders.status', $activeStatuses)
            ->whereIn('service_order_females.female_caravan_id', $caravanIds)
            ->pluck('service_order_females.female_caravan_id')
            ->toArray();

        return array_unique(array_merge($maleConflicts, $femaleConflicts));
    }

    public function verifyAnimalsSexAndOwnership(array $caravanIds, int $companyId): array
    {
        if (empty($caravanIds)) {
            return [];
        }

        return DB::table('caravans')
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->pluck('sex', 'id')
            ->toArray();
    }

    public function moveAnimalsToBatch(array $caravanIds, int $batchId, int $companyId, int $actionUserId): void
    {
        if (empty($caravanIds)) {
            return;
        }

        DB::transaction(function () use ($caravanIds, $batchId, $companyId) {
            // Update caravan batch_id
            DB::table('caravans')
                ->where('company_id', $companyId)
                ->whereIn('id', $caravanIds)
                ->update(['batch_id' => $batchId]);

            // Get target farm's renspa
            $renspa = '';
            $batch = DB::table('batches')
                ->join('farms', 'batches.farm_id', '=', 'farms.id')
                ->where('batches.id', $batchId)
                ->select('farms.renspa')
                ->first();
            if ($batch !== null) {
                $renspa = $batch->renspa ?? '';
            }

            $movements = [];
            $now = now()->toDateTimeString();
            foreach ($caravanIds as $caravanId) {
                $movements[] = [
                    'caravan_id' => $caravanId,
                    'company_id' => $companyId,
                    'renspa' => $renspa,
                    'type' => 'TRANSFER',
                    'movement_date' => $now,
                    'observations' => "Moved to service batch (ID: {$batchId}) by Service Order",
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('caravan_movements')->insert($movements);
        });
    }

    public function listAll(int $companyId): array
    {
        $models = ServiceOrder::with(['males', 'females', 'history'])
            ->where('company_id', $companyId)
            ->orderBy('id', 'desc')
            ->get();

        return $models->map(fn($model) => ServiceOrderMapper::toEntity($model))->toArray();
    }
}
