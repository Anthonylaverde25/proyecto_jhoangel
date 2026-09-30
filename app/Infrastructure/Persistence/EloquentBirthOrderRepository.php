<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\BirthOrderMapper;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Enums\BirthOrderAnimalStatus;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Interfaces\IBirthOrderRepository;
use App\Models\BirthOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentBirthOrderRepository implements IBirthOrderRepository
{
    private const DETAIL_RELATIONS = [
        'requestedByUser',
        'animals.sourceBatch',
        'animals.mother.batch',
        'animals.mother.categoryRelation',
        'animals.mother.subcategoryRelation',
        'animals.gestation.sires',
        'animals.calf',
        'animals.calfBatch',
        'history.actionUser',
    ];

    public function save(BirthOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): BirthOrderEntity
    {
        $id = DB::transaction(function () use ($order, $actionUserId, $reason, $metadata): int {
            $isNew = $order->getId() === null;
            $model = $isNew
                ? new BirthOrder()
                : BirthOrder::where('company_id', $order->getCompanyId())->findOrFail($order->getId());
            $oldStatus = $isNew ? null : $model->status;

            $model->fill([
                'company_id' => $order->getCompanyId(),
                'code' => $order->getCode(),
                'status' => $order->getStatus()->value,
                'kind' => $order->getKind()->value,
                'period_start' => $order->getPeriodStart(),
                'period_end' => $order->getPeriodEnd(),
                'planned_head_count' => $order->getPlannedHeadCount(),
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

            // A draft that was rewritten gets its roll replaced whole: it held nothing.
            if (!$isNew && $order->isRosterReplaced()) {
                $model->animals()->delete();
            }

            $this->syncAnimals($model, $order, $isNew || $order->isRosterReplaced());

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
            ?? throw new \RuntimeException('The birth order could not be read back after saving.');
    }

    public function findById(int $id, int $companyId): ?BirthOrderEntity
    {
        $model = BirthOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->find($id);

        return $model !== null ? BirthOrderMapper::toEntity($model) : null;
    }

    public function findByCode(string $code, int $companyId): ?BirthOrderEntity
    {
        $model = BirthOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        return $model !== null ? BirthOrderMapper::toEntity($model) : null;
    }

    public function list(int $companyId, ?string $status = null, ?string $kind = null): array
    {
        // The roll is loaded with its source batch but without the females: the list needs how many
        // calved and from where, not who.
        return BirthOrder::with(['requestedByUser', 'animals.sourceBatch'])
            ->where('company_id', $companyId)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->orderByDesc('id')
            ->get()
            ->map(fn (BirthOrder $model) => BirthOrderMapper::toEntity($model))
            ->all();
    }

    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string
    {
        $code = BirthOrder::where('company_id', $companyId)
            ->where('code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        return $code !== null ? (string) $code : null;
    }

    public function findCommittedMothers(array $motherIds, int $companyId, ?int $exceptOrderId = null): array
    {
        if ($motherIds === []) {
            return [];
        }

        return DB::table('birth_order_animals')
            ->join('birth_orders', 'birth_order_animals.birth_order_id', '=', 'birth_orders.id')
            ->where('birth_orders.company_id', $companyId)
            ->whereIn('birth_orders.status', [TransferOrderStatus::ISSUED->value, TransferOrderStatus::PARTIAL->value])
            ->where('birth_order_animals.status', BirthOrderAnimalStatus::PENDING->value)
            ->whereIn('birth_order_animals.mother_caravan_id', $motherIds)
            ->when($exceptOrderId !== null, fn ($query) => $query->where('birth_orders.id', '!=', $exceptOrderId))
            ->pluck('birth_orders.code', 'birth_order_animals.mother_caravan_id')
            ->mapWithKeys(fn ($code, $motherId) => [(int) $motherId => (string) $code])
            ->all();
    }

    public function openMothers(int $companyId): array
    {
        return DB::table('birth_order_animals')
            ->join('birth_orders', 'birth_order_animals.birth_order_id', '=', 'birth_orders.id')
            ->where('birth_orders.company_id', $companyId)
            ->whereIn('birth_orders.status', [TransferOrderStatus::ISSUED->value, TransferOrderStatus::PARTIAL->value])
            ->where('birth_order_animals.status', BirthOrderAnimalStatus::PENDING->value)
            ->pluck('birth_orders.code', 'birth_order_animals.mother_caravan_id')
            ->mapWithKeys(fn ($code, $motherId) => [(int) $motherId => (string) $code])
            ->all();
    }

    public function motherFacts(array $motherIds, int $companyId): array
    {
        if ($motherIds === []) {
            return [];
        }

        $gestationByMother = DB::table('caravan_gestations')
            ->whereIn('caravan_id', $motherIds)
            ->where('is_current', true)
            ->orderByDesc('id')
            ->get(['id', 'caravan_id'])
            ->reduce(function (array $carry, $row): array {
                $carry[(int) $row->caravan_id] ??= (int) $row->id;

                return $carry;
            }, []);

        $facts = [];

        DB::table('caravans')
            ->where('company_id', $companyId)
            ->whereIn('id', $motherIds)
            ->get(['id', 'identification', 'sex', 'batch_id'])
            ->each(function ($row) use (&$facts, $gestationByMother): void {
                $facts[(int) $row->id] = [
                    'identification' => (string) $row->identification,
                    'sex' => (string) $row->sex,
                    'batch_id' => $row->batch_id !== null ? (int) $row->batch_id : null,
                    'gestation_id' => $gestationByMother[(int) $row->id] ?? null,
                ];
            });

        return $facts;
    }

    public function lossReasonIdByCode(string $code, int $companyId): ?int
    {
        $id = DB::table('gestation_loss_reasons')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * New orders and rewritten drafts insert their roll whole. Afterwards a line only changes its
     * outcome, and a round may add unplanned lines.
     */
    private function syncAnimals(BirthOrder $model, BirthOrderEntity $order, bool $insertAll): void
    {
        $now = now();
        $current = $insertAll ? collect() : $model->animals()->get()->keyBy('mother_caravan_id');
        $inserts = [];

        foreach ($order->getAnimals() as $animal) {
            $row = $current->get($animal->getMotherCaravanId());
            $values = [
                'status' => $animal->getStatus()->value,
                'outcome' => $animal->getOutcome()?->value,
                'event_date' => $animal->getEventDate(),
                'calf_caravan_id' => $animal->getCalfCaravanId(),
                'calf_batch_id' => $animal->getCalfBatchId(),
                'executed_at' => $animal->getExecutedAt(),
                'observations' => $animal->getObservations(),
            ];

            if ($row === null) {
                $inserts[] = [
                    'company_id' => $model->company_id,
                    'birth_order_id' => $model->id,
                    'mother_caravan_id' => $animal->getMotherCaravanId(),
                    'gestation_id' => $animal->getGestationId(),
                    'source_batch_id' => $animal->getSourceBatchId(),
                    'unplanned' => $animal->isUnplanned(),
                    ...$values,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            if ($row->status !== $animal->getStatus()->value) {
                $row->update($values);
            }
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('birth_order_animals')->insert($chunk);
        }
    }
}
