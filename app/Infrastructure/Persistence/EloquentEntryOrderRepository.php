<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\EntryOrderMapper;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\EntryOrderIncidentStatus;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\ReceptionStatus;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Models\EntryOrder;
use App\Models\EntryOrderAnimal;
use App\Models\EntryOrderDte;
use App\Models\EntryOrderIncident;
use App\Models\EntryOrderReceiptSheet;
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
        'incidents',
        'receiptSheets',
    ];

    private const DETAIL_RELATIONS = [
        ...self::SUMMARY_RELATIONS,
        'dtes',
        'dtes.loadedByUser',
        'dtes.animals.caravan',
        'dtes.animals.breedLine',
        'incidents.raisedBy',
        'incidents.resolvedBy',
        'receiptSheets.issuedBy',
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
                'sex_composition' => $troop->sexComposition?->value,
                'male_count' => $troop->maleCount,
                'female_count' => $troop->femaleCount,
                'condition' => $troop->condition?->value,
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
            $this->updateReceptions($order);
            $this->insertNewIncidents($model, $order);
            $this->updateResolvedIncidents($order);
            $this->saveReceiptSheets($model, $order);

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

    public function list(int $companyId, ?string $status = null, ?int $providerId = null, bool $withOpenIncidents = false): array
    {
        return EntryOrder::with([
            ...self::SUMMARY_RELATIONS,
            // The list shows how far each DTE got without loading its caravans.
            'dtes' => fn ($query) => $query->withCount([
                'animals as pending_count' => fn ($q) => $q->where('reception_status', ReceptionStatus::PENDING->value),
                'animals as received_count' => fn ($q) => $q->where('reception_status', ReceptionStatus::RECEIVED->value),
                'animals as missing_count' => fn ($q) => $q->where('reception_status', ReceptionStatus::MISSING->value),
            ]),
        ])
            ->where('company_id', $companyId)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($providerId !== null, fn (Builder $query) => $query->where('provider_id', $providerId))
            ->when($withOpenIncidents, fn (Builder $query) => $query->whereHas(
                'incidents',
                fn (Builder $q) => $q->where('status', EntryOrderIncidentStatus::OPEN->value)
            ))
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

        $animals = DB::table('entry_order_animals')
            ->select(
                'entry_order_id',
                DB::raw('COUNT(*) as with_dte'),
                DB::raw("SUM(CASE WHEN reception_status = 'RECEIVED' THEN 1 ELSE 0 END) as received"),
                DB::raw("SUM(CASE WHEN reception_status = 'PENDING' THEN 1 ELSE 0 END) as in_transit")
            )
            ->where('company_id', $companyId)
            ->groupBy('entry_order_id');

        $incidents = DB::table('entry_order_incidents')
            ->select('entry_order_id', DB::raw('COUNT(*) as open_incidents'))
            ->where('company_id', $companyId)
            ->where('status', EntryOrderIncidentStatus::OPEN->value)
            ->groupBy('entry_order_id');

        $summaries = [];

        DB::table('entry_orders')
            ->leftJoinSub($animals, 'animals', 'animals.entry_order_id', '=', 'entry_orders.id')
            ->leftJoinSub($incidents, 'incidents', 'incidents.entry_order_id', '=', 'entry_orders.id')
            ->where('entry_orders.company_id', $companyId)
            ->whereIn('entry_orders.batch_id', $batchIds)
            ->where('entry_orders.status', '!=', EntryOrderStatus::CANCELLED->value)
            ->get([
                'entry_orders.id', 'entry_orders.code', 'entry_orders.status', 'entry_orders.batch_id', 'entry_orders.head_count',
                'animals.with_dte', 'animals.received', 'animals.in_transit', 'incidents.open_incidents',
            ])
            ->each(function ($row) use (&$summaries): void {
                $status = EntryOrderStatus::from($row->status);

                $summaries[(int) $row->batch_id] = [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'status' => $status->value,
                    'status_label' => $status->label(),
                    'head_count' => (int) $row->head_count,
                    'with_dte_count' => (int) ($row->with_dte ?? 0),
                    'received_count' => (int) ($row->received ?? 0),
                    'in_transit_count' => (int) ($row->in_transit ?? 0),
                    'open_incidents_count' => (int) ($row->open_incidents ?? 0),
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
                    // "Registrar ingreso" receives the DTE in the same operation, so it may not be PENDING.
                    'reception_status' => $animal->getReceptionStatus()->value,
                    'received_at' => $animal->getReceivedAt(),
                    'reception_method' => $animal->getReceptionMethod()?->value,
                    'received_by_user_id' => $animal->getReceivedByUserId(),
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

    /**
     * Writes how the stored caravans touched by this operation were received or declared missing.
     */
    private function updateReceptions(EntryOrderEntity $order): void
    {
        foreach ($order->changedAnimals() as $animal) {
            EntryOrderAnimal::withoutGlobalScopes()->whereKey($animal->getId())->update([
                'reception_status' => $animal->getReceptionStatus()->value,
                'received_at' => $animal->getReceivedAt(),
                'reception_method' => $animal->getReceptionMethod()?->value,
                'received_by_user_id' => $animal->getReceivedByUserId(),
                'caravan_movement_id' => $animal->getCaravanMovementId(),
            ]);
        }
    }

    /**
     * An incident raised by a DTE loaded in this same save points to it by number.
     */
    private function insertNewIncidents(EntryOrder $model, EntryOrderEntity $order): void
    {
        $unsaved = $order->unsavedIncidents();

        if ($unsaved === []) {
            return;
        }

        $dteIds = array_change_key_case(EntryOrderDte::withoutGlobalScopes()
            ->where('entry_order_id', $model->id)
            ->pluck('id', 'dte_number')
            ->all(), CASE_UPPER);

        foreach ($unsaved as $incident) {
            EntryOrderIncident::create([
                'company_id' => $model->company_id,
                'entry_order_id' => $model->id,
                'entry_order_dte_id' => $incident->getDteId()
                    ?? ($incident->getDteNumber() !== null ? ($dteIds[strtoupper($incident->getDteNumber())] ?? null) : null),
                'type' => $incident->getType()->value,
                'detail' => $incident->getDetail(),
                'metadata' => $incident->getMetadata(),
                'status' => $incident->getStatus()->value,
                'raised_by_user_id' => $incident->getRaisedByUserId(),
            ]);
        }
    }

    /**
     * ING-03 sheets issued in this operation, and the ones replaced, printed or scanned.
     */
    private function saveReceiptSheets(EntryOrder $model, EntryOrderEntity $order): void
    {
        foreach ($order->changedReceiptSheets() as $sheet) {
            EntryOrderReceiptSheet::withoutGlobalScopes()->whereKey($sheet->getId())->update([
                'status' => $sheet->getStatus()->value,
                'weighing_mode' => $sheet->getWeighingMode()->value,
                'processed_pages' => json_encode($sheet->getProcessedPages()),
                'printed_at' => $sheet->getPrintedAt(),
                'processed_at' => $sheet->getProcessedAt(),
                'replaced_at' => $sheet->getReplacedAt(),
            ]);
        }

        foreach ($order->unsavedReceiptSheets() as $sheet) {
            EntryOrderReceiptSheet::create([
                'company_id' => $model->company_id,
                'entry_order_id' => $model->id,
                'entry_order_dte_id' => $sheet->getDteId(),
                'number' => $sheet->getNumber(),
                'status' => $sheet->getStatus()->value,
                'weighing_mode' => $sheet->getWeighingMode()->value,
                'caravan_ids' => $sheet->getCaravanIds(),
                'page_count' => $sheet->getPageCount(),
                'processed_pages' => $sheet->getProcessedPages(),
                'issued_by_user_id' => $sheet->getIssuedByUserId(),
            ]);
        }
    }

    private function updateResolvedIncidents(EntryOrderEntity $order): void
    {
        foreach ($order->resolvedIncidents() as $incident) {
            EntryOrderIncident::withoutGlobalScopes()->whereKey($incident->getId())->update([
                'status' => $incident->getStatus()->value,
                'resolution' => $incident->getResolution(),
                'resolved_by_user_id' => $incident->getResolvedByUserId(),
                'resolved_at' => $incident->getResolvedAt(),
            ]);
        }
    }
}
