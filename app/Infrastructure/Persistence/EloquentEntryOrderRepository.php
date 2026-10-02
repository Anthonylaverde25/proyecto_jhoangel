<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\EntryOrderMapper;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Models\EntryOrder;
use App\Models\EntryOrderDte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentEntryOrderRepository implements IEntryOrderRepository
{
    private const SUMMARY_RELATIONS = [
        'provider',
        'farm',
        'batch',
        'category',
        'requestedByUser',
        'breeds.breed',
        'breeds.color',
        'dtes',
    ];

    private const DETAIL_RELATIONS = [
        ...self::SUMMARY_RELATIONS,
        'dtes.loadedByUser',
        'dtes.animals.caravan',
        'dtes.animals.breedLine',
        'history.actionUser',
    ];

    public function save(EntryOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): EntryOrderEntity
    {
        $id = DB::transaction(function () use ($order, $actionUserId, $reason, $metadata): int {
            $isNew = $order->getId() === null;
            $model = $isNew
                ? new EntryOrder()
                : EntryOrder::where('company_id', $order->getCompanyId())->findOrFail($order->getId());
            $oldStatus = $isNew ? null : $model->status;
            $troop = $order->getTroop();

            $model->fill([
                'company_id' => $order->getCompanyId(),
                'code' => $order->getCode(),
                'number' => $order->getNumber(),
                'status' => $order->getStatus()->value,
                'kind' => $order->getKind()->value,
                'provider_id' => $troop->providerId,
                'farm_id' => $troop->farmId,
                'auction_number' => $troop->auctionNumber,
                'batch_id' => $order->getBatchId(),
                'batch_name' => $order->getBatchName(),
                'batch_name_mode' => $order->getBatchNameMode()->value,
                'head_count' => $troop->headCount,
                'category_id' => $troop->categoryId,
                'sex_composition' => $troop->sexComposition->value,
                'male_count' => $troop->maleCount,
                'female_count' => $troop->femaleCount,
                'condition' => $troop->condition->value,
                'age_min_months' => $troop->ageMinMonths,
                'age_max_months' => $troop->ageMaxMonths,
                'knows_to_eat' => $troop->knowsToEat,
                'tick_vaccinated' => $troop->tickVaccinated,
                'shrink_percent' => $troop->shrinkPercent,
                'estimated_weight' => $troop->estimatedWeight,
                'min_weight' => $troop->minWeight,
                'max_weight' => $troop->maxWeight,
                'purchase_date' => $troop->purchaseDate,
                'requested_by_user_id' => $order->getRequestedByUserId(),
                'confirmed_at' => $order->getConfirmedAt(),
                'printed_at' => $order->getPrintedAt(),
                'first_dte_at' => $order->getFirstDteAt(),
                'closed_at' => $order->getClosedAt(),
                'responsable' => $troop->responsable,
                'observations' => $troop->observations,
                'closing_reason' => $order->getClosingReason(),
            ]);
            $model->save();

            // A draft that was rewritten gets its breeds replaced whole: no caravan points at them yet.
            if ($isNew || $order->isTroopReplaced()) {
                if (!$isNew) {
                    $model->breeds()->delete();
                }

                foreach ($troop->breeds as $breed) {
                    $model->breeds()->create([
                        'company_id' => $model->company_id,
                        'breed_id' => $breed->getBreedId(),
                        'color_id' => $breed->getColorId(),
                        'position' => $breed->getPosition(),
                    ]);
                }
            }

            $this->insertNewDtes($model, $order);

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
            ?? throw new \RuntimeException('The entry order could not be read back after saving.');
    }

    public function findById(int $id, int $companyId): ?EntryOrderEntity
    {
        $model = EntryOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->find($id);

        return $model !== null ? EntryOrderMapper::toEntity($model) : null;
    }

    public function findByCode(string $code, int $companyId): ?EntryOrderEntity
    {
        $model = EntryOrder::with(self::DETAIL_RELATIONS)
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        return $model !== null ? EntryOrderMapper::toEntity($model) : null;
    }

    public function list(int $companyId, ?string $status = null, ?int $providerId = null): array
    {
        return EntryOrder::with(self::SUMMARY_RELATIONS)
            ->where('company_id', $companyId)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($providerId !== null, fn (Builder $query) => $query->where('provider_id', $providerId))
            ->orderByDesc('id')
            ->get()
            ->map(fn (EntryOrder $model) => EntryOrderMapper::toEntity($model))
            ->all();
    }

    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string
    {
        $code = EntryOrder::where('company_id', $companyId)
            ->where('code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        return $code !== null ? (string) $code : null;
    }

    public function lastNumber(int $companyId): int
    {
        return (int) EntryOrder::where('company_id', $companyId)
            ->lockForUpdate()
            ->max('number');
    }

    public function orderCodeOfDte(string $dteNumber, int $companyId): ?string
    {
        $code = DB::table('entry_order_dtes')
            ->join('entry_orders', 'entry_order_dtes.entry_order_id', '=', 'entry_orders.id')
            ->where('entry_order_dtes.company_id', $companyId)
            ->whereRaw('UPPER(entry_order_dtes.dte_number) = ?', [strtoupper(trim($dteNumber))])
            ->value('entry_orders.code');

        return $code !== null ? (string) $code : null;
    }

    public function summariesByBatch(array $batchIds, int $companyId): array
    {
        if ($batchIds === []) {
            return [];
        }

        $entered = DB::table('entry_order_dtes')
            ->select('entry_order_id', DB::raw('SUM(head_count) as entered'))
            ->where('company_id', $companyId)
            ->groupBy('entry_order_id');

        $summaries = [];

        DB::table('entry_orders')
            ->leftJoinSub($entered, 'entered', 'entered.entry_order_id', '=', 'entry_orders.id')
            ->where('entry_orders.company_id', $companyId)
            ->whereIn('entry_orders.batch_id', $batchIds)
            ->where('entry_orders.status', '!=', EntryOrderStatus::CANCELLED->value)
            ->get(['entry_orders.id', 'entry_orders.code', 'entry_orders.status', 'entry_orders.batch_id', 'entry_orders.head_count', 'entered.entered'])
            ->each(function ($row) use (&$summaries): void {
                $status = EntryOrderStatus::from($row->status);

                $summaries[(int) $row->batch_id] = [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'status' => $status->value,
                    'status_label' => $status->label(),
                    'head_count' => (int) $row->head_count,
                    'entered_count' => (int) ($row->entered ?? 0),
                ];
            });

        return $summaries;
    }

    /**
     * A DTE is written once, with its caravans, and never rewritten: the caravans it created exist.
     */
    private function insertNewDtes(EntryOrder $model, EntryOrderEntity $order): void
    {
        $unsaved = $order->unsavedDtes();

        if ($unsaved === []) {
            return;
        }

        $breedIdByPosition = $model->breeds()->pluck('id', 'position')->all();

        foreach ($unsaved as $dte) {
            $row = EntryOrderDte::create([
                'company_id' => $model->company_id,
                'entry_order_id' => $model->id,
                'dte_number' => $dte->getDteNumber(),
                'dte_date' => $dte->getDteDate(),
                'entered_at' => $dte->getEnteredAt(),
                'head_count' => $dte->getHeadCount(),
                'loaded_by_user_id' => $dte->getLoadedByUserId(),
                'observations' => $dte->getObservations(),
            ]);

            $now = now();
            $lines = [];

            foreach ($dte->getAnimals() as $animal) {
                $lines[] = [
                    'company_id' => $model->company_id,
                    'entry_order_id' => $model->id,
                    'entry_order_dte_id' => $row->id,
                    'caravan_id' => $animal->getCaravanId(),
                    'entry_order_breed_id' => $animal->getBreedPosition() !== null
                        ? ($breedIdByPosition[$animal->getBreedPosition()] ?? null)
                        : null,
                    'caravan_movement_id' => $animal->getCaravanMovementId(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($lines, 500) as $chunk) {
                DB::table('entry_order_animals')->insert($chunk);
            }
        }
    }
}
