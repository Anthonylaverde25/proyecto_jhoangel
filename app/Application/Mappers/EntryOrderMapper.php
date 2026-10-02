<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\BatchNameMode;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TroopCondition;
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

        $dtes = [];

        if ($model->relationLoaded('dtes')) {
            foreach ($model->dtes as $dte) {
                $animals = [];

                if ($dte->relationLoaded('animals')) {
                    foreach ($dte->animals as $line) {
                        $caravan = $line->relationLoaded('caravan') ? $line->caravan : null;
                        $breedLine = $line->relationLoaded('breedLine') ? $line->breedLine : null;
                        $sex = $caravan?->sex;

                        $animals[] = new EntryOrderAnimalEntity(
                            id: (int) $line->id,
                            caravanId: (int) $line->caravan_id,
                            identification: (string) $caravan?->identification,
                            sex: $sex instanceof \BackedEnum ? (string) $sex->value : (string) $sex,
                            breedPosition: $breedLine?->position,
                            caravanMovementId: $line->caravan_movement_id,
                            entryWeight: $caravan?->entry_weight !== null ? (float) $caravan->entry_weight : null
                        );
                    }
                }

                $dtes[] = new EntryOrderDteEntity(
                    id: (int) $dte->id,
                    dteNumber: (string) $dte->dte_number,
                    dteDate: self::date($dte->dte_date),
                    enteredAt: self::date($dte->entered_at),
                    animals: $animals,
                    loadedByUserId: $dte->loaded_by_user_id,
                    observations: $dte->observations,
                    loadedByUserName: $dte->relationLoaded('loadedByUser') ? $dte->loadedByUser?->name : null,
                    createdAt: $dte->created_at,
                    storedHeadCount: (int) $dte->head_count
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
            headCount: (int) $model->head_count,
            categoryId: (int) $model->category_id,
            sexComposition: SexComposition::from($model->sex_composition),
            maleCount: $model->male_count,
            femaleCount: $model->female_count,
            condition: TroopCondition::from($model->condition),
            ageMinMonths: $model->age_min_months,
            ageMaxMonths: $model->age_max_months,
            knowsToEat: (bool) $model->knows_to_eat,
            tickVaccinated: (bool) $model->tick_vaccinated,
            shrinkPercent: $model->shrink_percent,
            estimatedWeight: (float) $model->estimated_weight,
            minWeight: $model->min_weight,
            maxWeight: $model->max_weight,
            purchaseDate: self::date($model->purchase_date),
            responsable: $model->responsable,
            observations: $model->observations,
            breeds: $breeds
        );

        $farm = $model->relationLoaded('farm') ? $model->farm : null;
        $category = $model->relationLoaded('category') ? $model->category : null;

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
            history: $history,
            createdAt: $model->created_at,
            names: [
                'provider' => $model->relationLoaded('provider') ? $model->provider?->name : null,
                'provider_cuit' => $model->relationLoaded('provider') ? $model->provider?->cuit : null,
                'farm' => $farm?->name,
                'farm_renspa' => $farm?->renspa,
                'batch' => $model->relationLoaded('batch') ? $model->batch?->name : null,
                'category' => $category?->name,
                'category_sex' => $category?->sex,
                'requested_by' => $model->relationLoaded('requestedByUser') ? $model->requestedByUser?->name : null,
            ]
        );
    }

    private static function date(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
