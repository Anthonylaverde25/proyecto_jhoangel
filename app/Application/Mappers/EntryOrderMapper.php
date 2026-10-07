<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\EntryOrderIncidentEntity;
use App\Core\Entities\EntryOrderReceiptSheetEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\BatchNameMode;
use App\Core\Enums\EntryOrderIncidentStatus;
use App\Core\Enums\EntryOrderIncidentType;
use App\Core\Enums\ReceiptSheetStatus;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\ReceptionMethod;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TroopCondition;
use App\Core\Enums\ArrivalFinding;
use App\Core\Enums\ReferenceMode;
use App\Core\Enums\WeighingMode;
use App\Core\ValueObjects\EntryTroop;
use App\Models\EntryOrder;

class EntryOrderMapper
{
    public static function toEntity(EntryOrder $model): EntryOrderEntity
    {
        $breeds = [];

        if ($model->relationLoaded('breeds')) {
            foreach ($model->breeds as $line) {
                $breeds[] = new EntryOrderBreedEntity(
                    id: (int) $line->id,
                    position: (int) $line->position,
                    breedId: (int) $line->breed_id,
                    colorId: $line->color_id,
                    breedName: $line->relationLoaded('breed') ? $line->breed?->name : null,
                    colorName: $line->relationLoaded('color') ? $line->color?->name : null
                );
            }
        }

        $categories = [];

        if ($model->relationLoaded('categories')) {
            foreach ($model->categories as $line) {
                $category = $line->relationLoaded('category') ? $line->category : null;

                $categories[] = new EntryOrderCategoryEntity(
                    id: (int) $line->id,
                    position: (int) $line->position,
                    categoryId: (int) $line->category_id,
                    headCount: $line->head_count,
                    categoryName: $category?->name,
                    categorySex: $category?->sex
                );
            }
        }

        $dtes = [];

        if ($model->relationLoaded('dtes')) {
            foreach ($model->dtes as $dte) {
                $animals = [];

                if ($dte->relationLoaded('animals')) {
                    foreach ($dte->animals as $line) {
                        $caravan = $line->relationLoaded('caravan') ? $line->caravan : null;
                        $breedLine = $line->relationLoaded('breedLine') ? $line->breedLine : null;
                        $categoryLine = $line->relationLoaded('categoryLine') ? $line->categoryLine : null;
                        $sex = $caravan?->sex;

                        $animals[] = new EntryOrderAnimalEntity(
                            id: (int) $line->id,
                            caravanId: (int) $line->caravan_id,
                            identification: (string) $caravan?->identification,
                            sex: $sex instanceof \BackedEnum ? (string) $sex->value : (string) $sex,
                            breedPosition: $breedLine?->position,
                            caravanMovementId: $line->caravan_movement_id,
                            entryWeight: $caravan?->entry_weight !== null ? (float) $caravan->entry_weight : null,
                            receivedAt: self::date($line->received_at),
                            receptionMethod: ReceptionMethod::tryFrom((string) $line->reception_method),
                            receivedByUserId: $line->received_by_user_id,
                            categoryPosition: $categoryLine?->position,
                            arrivalFindings: $line->relationLoaded('arrivalFindings')
                                ? array_values(array_filter($line->arrivalFindings->map(fn ($f) => ArrivalFinding::tryFrom((string) $f->finding))->all()))
                                : []
                        );
                    }
                }

                $dtes[] = new EntryOrderDteEntity(
                    id: (int) $dte->id,
                    dteNumber: (string) $dte->dte_number,
                    dteDate: self::date($dte->dte_date),
                    headCount: (int) $dte->head_count,
                    missingHeadCount: (int) $dte->missing_head_count,
                    animals: $animals,
                    loadedByUserId: $dte->loaded_by_user_id,
                    observations: $dte->observations,
                    loadedByUserName: $dte->relationLoaded('loadedByUser') ? $dte->loadedByUser?->name : null,
                    createdAt: $dte->created_at,
                    // The list counts the caravans instead of loading them.
                    storedReceivedCount: $dte->relationLoaded('animals') ? null : (int) ($dte->received_count ?? 0),
                    uncaravanedHeadCount: (int) $dte->uncaravaned_head_count
                );
            }
        }

        $incidents = [];

        if ($model->relationLoaded('incidents')) {
            $dteNumbers = $model->relationLoaded('dtes') ? $model->dtes->pluck('dte_number', 'id')->all() : [];

            foreach ($model->incidents as $incident) {
                $incidents[] = new EntryOrderIncidentEntity(
                    id: (int) $incident->id,
                    type: EntryOrderIncidentType::from($incident->type),
                    detail: (string) $incident->detail,
                    metadata: $incident->metadata ?? [],
                    status: EntryOrderIncidentStatus::from($incident->status),
                    dteId: $incident->entry_order_dte_id,
                    dteNumber: $incident->entry_order_dte_id !== null ? ($dteNumbers[$incident->entry_order_dte_id] ?? null) : null,
                    resolution: $incident->resolution,
                    resolvedByUserId: $incident->resolved_by_user_id,
                    resolvedAt: $incident->resolved_at,
                    raisedByUserId: $incident->raised_by_user_id,
                    createdAt: $incident->created_at,
                    raisedByUserName: $incident->relationLoaded('raisedBy') ? $incident->raisedBy?->name : null,
                    resolvedByUserName: $incident->relationLoaded('resolvedBy') ? $incident->resolvedBy?->name : null
                );
            }
        }

        $receiptSheets = [];

        if ($model->relationLoaded('receiptSheets')) {
            $dteNumbers ??= $model->relationLoaded('dtes') ? $model->dtes->pluck('dte_number', 'id')->all() : [];

            foreach ($model->receiptSheets as $sheet) {
                $receiptSheets[] = new EntryOrderReceiptSheetEntity(
                    id: (int) $sheet->id,
                    number: (int) $sheet->number,
                    dteId: (int) $sheet->entry_order_dte_id,
                    dteNumber: (string) ($dteNumbers[$sheet->entry_order_dte_id] ?? ''),
                    status: ReceiptSheetStatus::from($sheet->status),
                    dteHeadCount: (int) $sheet->dte_head_count,
                    expectedHeadCount: (int) $sheet->expected_head_count,
                    rowCount: (int) $sheet->row_count,
                    pageCount: (int) $sheet->page_count,
                    processedPages: array_map('intval', $sheet->processed_pages ?? []),
                    issuedByUserId: $sheet->issued_by_user_id,
                    printedAt: $sheet->printed_at,
                    processedAt: $sheet->processed_at,
                    replacedAt: $sheet->replaced_at,
                    createdAt: $sheet->created_at,
                    issuedByUserName: $sheet->relationLoaded('issuedBy') ? $sheet->issuedBy?->name : null,
                    weighingMode: WeighingMode::tryFrom((string) $sheet->weighing_mode) ?? WeighingMode::INDIVIDUAL,
                    referenceMode: ReferenceMode::tryFrom((string) $sheet->reference_mode) ?? ReferenceMode::CODE
                );
            }
        }

        $history = [];

        if ($model->relationLoaded('history')) {
            foreach ($model->history as $entry) {
                $history[] = new TransferOrderHistoryEntity(
                    id: (int) $entry->id,
                    fromStatus: $entry->from_status,
                    toStatus: $entry->to_status,
                    actionUserId: $entry->action_user_id,
                    actionUserName: $entry->relationLoaded('actionUser') ? $entry->actionUser?->name : null,
                    reason: $entry->action_reason,
                    metadata: $entry->action_metadata,
                    createdAt: $entry->created_at
                );
            }
        }

        $troop = new EntryTroop(
            providerId: (int) $model->provider_id,
            farmId: (int) $model->farm_id,
            auctionNumber: $model->auction_number,
            categories: $categories,
            sexComposition: SexComposition::tryFrom((string) $model->sex_composition),
            maleCount: $model->male_count,
            femaleCount: $model->female_count,
            condition: TroopCondition::tryFrom((string) $model->condition),
            ageMinMonths: $model->age_min_months,
            ageMaxMonths: $model->age_max_months,
            knowsToEat: $model->knows_to_eat !== null ? (bool) $model->knows_to_eat : null,
            tickVaccinated: $model->tick_vaccinated !== null ? (bool) $model->tick_vaccinated : null,
            shrinkPercent: $model->shrink_percent,
            estimatedWeight: $model->estimated_weight !== null ? (float) $model->estimated_weight : null,
            minWeight: $model->min_weight,
            maxWeight: $model->max_weight,
            purchaseDate: self::date($model->purchase_date),
            responsable: $model->responsable,
            observations: $model->observations,
            breeds: $breeds
        );

        $farm = $model->relationLoaded('farm') ? $model->farm : null;

        return new EntryOrderEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            code: $model->code,
            number: (int) $model->number,
            status: EntryOrderStatus::from($model->status),
            kind: TransferOrderKind::tryFrom((string) $model->kind) ?? TransferOrderKind::PLANNED,
            troop: $troop,
            batchName: $model->batch_name,
            batchNameMode: BatchNameMode::tryFrom((string) $model->batch_name_mode) ?? BatchNameMode::CUSTOM,
            batchId: $model->batch_id,
            requestedByUserId: $model->requested_by_user_id,
            confirmedAt: $model->confirmed_at,
            printedAt: $model->printed_at,
            firstDteAt: $model->first_dte_at,
            closedAt: $model->closed_at,
            closingReason: $model->closing_reason,
            dtes: $dtes,
            incidents: $incidents,
            history: $history,
            createdAt: $model->created_at,
            receiptSheets: $receiptSheets,
            names: [
                'provider' => $model->relationLoaded('provider') ? $model->provider?->name : null,
                'provider_cuit' => $model->relationLoaded('provider') ? $model->provider?->cuit : null,
                'farm' => $farm?->name,
                'farm_renspa' => $farm?->renspa,
                'batch' => $model->relationLoaded('batch') ? $model->batch?->name : null,
                'requested_by' => $model->relationLoaded('requestedByUser') ? $model->requestedByUser?->name : null,
            ]
        );
    }

    private static function date(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
