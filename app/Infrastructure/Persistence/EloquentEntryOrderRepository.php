<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\EntryOrderMapper;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\EntryOrderIncidentStatus;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Models\EntryOrder;
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
        'categories.category',
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
        'dtes.animals.categoryLine',
        'dtes.animals.arrivalFindings',
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

            // A draft that was rewritten gets its categories and breeds replaced whole: no caravan
            // points at them yet.
            if ($isNew || $order->isTroopReplaced()) {
                if (!$isNew) {
                    $model->categories()->delete();
                    $model->breeds()->delete();
                }

                foreach ($troop->categories as $line) {
                    $model->categories()->create([
                        'company_id' => $model->company_id,
                        'category_id' => $line->getCategoryId(),
                        'head_count' => $line->getHeadCount(),
                        'position' => $line->getPosition(),
                    ]);
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
            $this->saveDteChanges($model, $order);
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
            'dtes' => fn ($query) => $query->withCount('animals as received_count'),
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

        $receivedByDte = DB::table('entry_order_animals')
            ->select('entry_order_dte_id', DB::raw('COUNT(*) as received'))
            ->where('company_id', $companyId)
            ->groupBy('entry_order_dte_id');

        // Received is the caravans plus the head counted without caravan. In transit is counted per
        // DTE: declared minus received minus declared missing, never below zero.
        $pending = 'd.head_count - COALESCE(r.received, 0) - d.uncaravaned_head_count - d.missing_head_count';
        $animals = DB::table('entry_order_dtes as d')
            ->leftJoinSub($receivedByDte, 'r', 'r.entry_order_dte_id', '=', 'd.id')
            ->select(
                'd.entry_order_id',
                DB::raw('SUM(d.head_count) as with_dte'),
                DB::raw('SUM(COALESCE(r.received, 0) + d.uncaravaned_head_count) as received'),
                DB::raw('SUM(d.uncaravaned_head_count) as uncaravaned'),
                DB::raw("SUM(CASE WHEN {$pending} > 0 THEN {$pending} ELSE 0 END) as in_transit")
            )
            ->where('d.company_id', $companyId)
            ->groupBy('d.entry_order_id');

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
                'animals.with_dte', 'animals.received', 'animals.uncaravaned', 'animals.in_transit', 'incidents.open_incidents',
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
                    'uncaravaned_count' => (int) ($row->uncaravaned ?? 0),
                    'in_transit_count' => (int) ($row->in_transit ?? 0),
                    'open_incidents_count' => (int) ($row->open_incidents ?? 0),
                ];
            });

        return $summaries;
    }

    /**
     * A DTE is written once; later only its declared and missing head change (saveDteChanges).
     */
    private function insertNewDtes(EntryOrder $model, EntryOrderEntity $order): void
    {
        foreach ($order->unsavedDtes() as $dte) {
            $row = EntryOrderDte::create([
                'company_id' => $model->company_id,
                'entry_order_id' => $model->id,
                'dte_number' => $dte->getDteNumber(),
                'dte_date' => $dte->getDteDate(),
                'head_count' => $dte->getHeadCount(),
                'missing_head_count' => $dte->getMissingHeadCount(),
                'uncaravaned_head_count' => $dte->getUncaravanedHeadCount(),
                'loaded_by_user_id' => $dte->getLoadedByUserId(),
                'observations' => $dte->getObservations(),
            ]);

            // "Registrar ingreso" receives the DTE in the same operation.
            $this->insertAnimals($model, (int) $row->id, $dte);
        }
    }

    /**
     * Stored DTEs: a correction of their head, head declared missing or counted without caravan, and the caravans received
     * on them in this operation.
     */
    private function saveDteChanges(EntryOrder $model, EntryOrderEntity $order): void
    {
        foreach ($order->changedDtes() as $dte) {
            EntryOrderDte::withoutGlobalScopes()->whereKey($dte->getId())->update([
                'head_count' => $dte->getHeadCount(),
                'missing_head_count' => $dte->getMissingHeadCount(),
                'uncaravaned_head_count' => $dte->getUncaravanedHeadCount(),
            ]);
        }

        foreach ($order->getDtes() as $dte) {
            if ($dte->getId() !== null) {
                $this->insertAnimals($model, $dte->getId(), $dte);
            }
        }
    }

    /**
     * The caravans received on a DTE that are not stored yet.
     */
    private function insertAnimals(EntryOrder $model, int $dteId, EntryOrderDteEntity $dte): void
    {
        $unsaved = $dte->unsavedAnimals();

        if ($unsaved === []) {
            return;
        }

        $breedIdByPosition = $model->breeds()->pluck('id', 'position')->all();
        $categoryIdByPosition = $model->categories()->pluck('id', 'position')->all();
        $now = now();
        $lines = [];

        foreach ($unsaved as $animal) {
            $lines[] = [
                'company_id' => $model->company_id,
                'entry_order_id' => $model->id,
                'entry_order_dte_id' => $dteId,
                'caravan_id' => $animal->getCaravanId(),
                'received_at' => $animal->getReceivedAt(),
                'reception_method' => $animal->getReceptionMethod()?->value,
                'received_by_user_id' => $animal->getReceivedByUserId(),
                'entry_order_breed_id' => $animal->getBreedPosition() !== null
                    ? ($breedIdByPosition[$animal->getBreedPosition()] ?? null)
                    : null,
                'entry_order_category_id' => $animal->getCategoryPosition() !== null
                    ? ($categoryIdByPosition[$animal->getCategoryPosition()] ?? null)
                    : null,
                'caravan_movement_id' => $animal->getCaravanMovementId(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($lines, 500) as $chunk) {
            DB::table('entry_order_animals')->insert($chunk);
        }

        $this->insertArrivalFindings($model, $dteId, $unsaved);
    }

    /**
     * The boxes marked on the lines just stored. The lines go in bulk, so they are looked up again
     * by caravan: a caravan is received once per DTE.
     *
     * @param EntryOrderAnimalEntity[] $animals
     */
    private function insertArrivalFindings(EntryOrder $model, int $dteId, array $animals): void
    {
        $marked = array_filter($animals, fn (EntryOrderAnimalEntity $a) => $a->getArrivalFindings() !== []);

        if ($marked === []) {
            return;
        }

        $lineIds = DB::table('entry_order_animals')
            ->where('entry_order_dte_id', $dteId)
            ->whereIn('caravan_id', array_map(fn (EntryOrderAnimalEntity $a) => $a->getCaravanId(), $marked))
            ->pluck('id', 'caravan_id');
        $now = now();
        $rows = [];

        foreach ($marked as $animal) {
            foreach ($animal->getArrivalFindings() as $finding) {
                $rows[] = [
                    'company_id' => $model->company_id,
                    'entry_order_animal_id' => $lineIds[$animal->getCaravanId()],
                    'caravan_id' => $animal->getCaravanId(),
                    'entry_order_dte_id' => $dteId,
                    'finding' => $finding->value,
                    'observed_at' => $animal->getReceivedAt(),
                    'entry_order_receipt_sheet_id' => $animal->getReceiptSheetId(),
                    'recorded_by_user_id' => $animal->getReceivedByUserId(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('entry_order_arrival_findings')->insert($chunk);
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
                'reference_mode' => $sheet->getReferenceMode()->value,
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
                'reference_mode' => $sheet->getReferenceMode()->value,
                'dte_head_count' => $sheet->getDteHeadCount(),
                'expected_head_count' => $sheet->getExpectedHeadCount(),
                'row_count' => $sheet->getRowCount(),
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
