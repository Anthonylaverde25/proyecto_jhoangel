<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\ExitOrderMapper;
use App\Core\Entities\ExitOrderAnimalEntity;
use App\Core\Entities\ExitOrderDteEntity;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Enums\ExitOrderAnimalStatus;
use App\Core\Enums\ExitOrderStatus;
use App\Core\Interfaces\IExitOrderRepository;
use App\Core\ValueObjects\ExitOrderCriteria;
use App\Models\ExitOrder;
use App\Models\ExitOrderAnimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentExitOrderRepository implements IExitOrderRepository
{
    private const DETAIL_RELATIONS = [
        'buyer',
        'animals.caravan.lineage.mother',
        'animals.fromBatch',
        'animals.activity',
        'animals.category',
        'dtes',
        'history.actionUser',
    ];

    public function save(ExitOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): ExitOrderEntity
    {
        $id = DB::transaction(function () use ($order, $actionUserId, $reason, $metadata): int {
            $isNew = $order->getId() === null;
            $model = $isNew
                ? new ExitOrder()
                : ExitOrder::withoutGlobalScopes()->where('company_id', $order->getCompanyId())->findOrFail($order->getId());
            $oldStatus = $isNew ? null : $model->status;

            $model->fill([
                'company_id' => $order->getCompanyId(),
                'code' => $order->getCode(),
                'kind' => $order->getKind()->value,
                'reason' => $order->getReason()->value,
                'status' => $order->getStatus()->value,
                'buyer_partner_id' => $order->getBuyerPartnerId(),
                'exit_date' => $order->getExitDate(),
                'planned_head_count' => $order->getPlannedHeadCount(),
                'requested_by_user_id' => $order->getRequestedByUserId(),
                'emitted_at' => $order->getEmittedAt(),
                'first_executed_at' => $order->getFirstExecutedAt(),
                'closed_at' => $order->getClosedAt(),
                'responsable' => $order->getResponsable(),
                'observations' => $order->getObservations(),
                'closing_reason' => $order->getClosingReason(),
            ]);
            $model->save();

            if (!$isNew && $order->isRosterReplaced()) {
                ExitOrderAnimal::withoutGlobalScopes()->where('exit_order_id', $model->id)->delete();
            }

            $this->syncAnimals($model, $order);

            if ($isNew || $oldStatus !== $model->status || $metadata !== null || $reason !== null) {
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
            ?? throw new \RuntimeException('The exit order could not be read back after saving.');
    }

    /**
     * Lines without id are inserted (a new roll, or a calf that left with its mother); the others
     * are updated only when their status changed.
     */
    private function syncAnimals(ExitOrder $model, ExitOrderEntity $order): void
    {
        $current = ExitOrderAnimal::withoutGlobalScopes()
            ->where('exit_order_id', $model->id)
            ->get()
            ->keyBy('caravan_id');

        $now = now();
        $inserts = [];

        foreach ($order->getAnimals() as $animal) {
            $row = $current->get($animal->getCaravanId());

            if ($row === null) {
                $inserts[] = ['company_id' => $model->company_id, 'exit_order_id' => $model->id, 'caravan_id' => $animal->getCaravanId(), 'created_at' => $now, 'updated_at' => $now]
                    + $this->lineAttributes($animal);
                continue;
            }

            if ($row->status !== $animal->getStatus()->value) {
                $row->update($this->lineAttributes($animal));
            }
        }

        // A roll of two hundred head is one insert, not two hundred.
        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('exit_order_animals')->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function lineAttributes(ExitOrderAnimalEntity $animal): array
    {
        $snapshot = $animal->getSnapshot();

        return [
            'status' => $animal->getStatus()->value,
            'from_batch_id' => $snapshot?->fromBatchId,
            'activity_id' => $snapshot?->activityId,
            'category_id' => $snapshot?->categoryId,
            'was_pregnant' => $snapshot?->wasPregnant,
            'weight_kg' => $animal->getWeightKg(),
            'price_per_kg' => $animal->getPricePerKg(),
            'amount' => $animal->getAmount(),
            'exited_at' => $animal->getExitedAt(),
            'caravan_movement_id' => $animal->getCaravanMovementId(),
            'caravan_weight_id' => $animal->getCaravanWeightId(),
            'skipped_reason' => $animal->getSkippedReason(),
        ];
    }

    public function findById(int $id, int $companyId): ?ExitOrderEntity
    {
        $model = ExitOrder::withoutGlobalScopes()
            ->with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->find($id);

        return $model !== null ? ExitOrderMapper::toEntity($model) : null;
    }

    public function findByCode(string $code, int $companyId): ?ExitOrderEntity
    {
        $model = ExitOrder::withoutGlobalScopes()
            ->with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        return $model !== null ? ExitOrderMapper::toEntity($model) : null;
    }

    public function list(ExitOrderCriteria $criteria): array
    {
        // The roll is loaded without the caravans: the tray needs how many left, not who.
        return ExitOrder::withoutGlobalScopes()
            ->with(['buyer', 'animals', 'dtes'])
            ->where('company_id', $criteria->companyId)
            ->when($criteria->status !== null, fn (Builder $q) => $q->where('status', $criteria->status->value))
            ->when($criteria->kind !== null, fn (Builder $q) => $q->where('kind', $criteria->kind->value))
            ->when($criteria->buyerPartnerId !== null, fn (Builder $q) => $q->where('buyer_partner_id', $criteria->buyerPartnerId))
            ->when($criteria->from !== null, fn (Builder $q) => $q->where('exit_date', '>=', $criteria->from))
            ->when($criteria->to !== null, fn (Builder $q) => $q->where('exit_date', '<=', $criteria->to))
            ->when($criteria->activityId !== null, fn (Builder $q) => $q->whereHas(
                'animals',
                fn (Builder $lines) => $lines->where('activity_id', $criteria->activityId)
            ))
            ->when($criteria->withoutDte, fn (Builder $q) => $q
                ->whereDoesntHave('dtes')
                ->whereHas('animals', fn (Builder $lines) => $lines->where('status', ExitOrderAnimalStatus::EXITED->value)))
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExitOrder $model) => ExitOrderMapper::toEntity($model))
            ->all();
    }

    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string
    {
        $code = ExitOrder::withoutGlobalScopes()
            ->where('company_id', $companyId)
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

        return DB::table('exit_order_animals')
            ->join('exit_orders', 'exit_order_animals.exit_order_id', '=', 'exit_orders.id')
            ->where('exit_orders.company_id', $companyId)
            ->whereIn('exit_orders.status', [ExitOrderStatus::ISSUED->value, ExitOrderStatus::PARTIAL->value])
            ->where('exit_order_animals.status', ExitOrderAnimalStatus::PENDING->value)
            ->whereIn('exit_order_animals.caravan_id', $caravanIds)
            ->pluck('exit_orders.code', 'exit_order_animals.caravan_id')
            ->mapWithKeys(fn ($code, $caravanId) => [(int) $caravanId => (string) $code])
            ->all();
    }

    public function countByBuyer(int $partnerId, int $companyId): int
    {
        return ExitOrder::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('buyer_partner_id', $partnerId)
            ->count();
    }

    public function dteNumberTaken(string $dteNumber, int $companyId): bool
    {
        return DB::table('exit_order_dtes')
            ->where('company_id', $companyId)
            ->where('dte_number', $dteNumber)
            ->exists();
    }

    public function addDte(ExitOrderEntity $order, ExitOrderDteEntity $dte, ?int $actionUserId): void
    {
        DB::transaction(function () use ($order, $dte, $actionUserId): void {
            $model = ExitOrder::withoutGlobalScopes()->where('company_id', $order->getCompanyId())->findOrFail($order->getId());

            $model->dtes()->create([
                'company_id' => $model->company_id,
                'dte_number' => $dte->dteNumber,
                'dte_date' => $dte->dteDate,
                'head_count' => $dte->headCount,
                'loaded_by_user_id' => $actionUserId,
                'observations' => $dte->observations,
            ]);

            $model->history()->create([
                'company_id' => $model->company_id,
                'from_status' => $model->status,
                'to_status' => $model->status,
                'action_user_id' => $actionUserId,
                'action_reason' => "DTE {$dte->dteNumber} cargado",
                'action_metadata' => ['dte_number' => $dte->dteNumber, 'head_count' => $dte->headCount],
            ]);
        });
    }

    public function removeDte(ExitOrderEntity $order, int $dteId, ?int $actionUserId): bool
    {
        return DB::transaction(function () use ($order, $dteId, $actionUserId): bool {
            $model = ExitOrder::withoutGlobalScopes()->where('company_id', $order->getCompanyId())->findOrFail($order->getId());
            $dte = $model->dtes()->whereKey($dteId)->first();

            if ($dte === null) {
                return false;
            }

            $dte->delete();
            $model->history()->create([
                'company_id' => $model->company_id,
                'from_status' => $model->status,
                'to_status' => $model->status,
                'action_user_id' => $actionUserId,
                'action_reason' => "DTE {$dte->dte_number} quitado",
                'action_metadata' => ['dte_number' => $dte->dte_number],
            ]);

            return true;
        });
    }
}
