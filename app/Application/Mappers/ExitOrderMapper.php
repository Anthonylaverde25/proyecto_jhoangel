<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\ExitOrderAnimalEntity;
use App\Core\Entities\ExitOrderDteEntity;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Entities\ExitOrderHistoryEntity;
use App\Core\Enums\ExitOrderAnimalStatus;
use App\Core\Enums\ExitOrderKind;
use App\Core\Enums\ExitOrderStatus;
use App\Core\Enums\ExitReason;
use App\Core\ValueObjects\AnimalLabel;
use App\Core\ValueObjects\ExitSnapshot;
use App\Models\ExitOrder;
use App\Models\ExitOrderAnimal;
use App\Models\ExitOrderDte;
use App\Models\ExitOrderHistory;

class ExitOrderMapper
{
    public static function toEntity(ExitOrder $model): ExitOrderEntity
    {
        return new ExitOrderEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            code: (string) $model->code,
            kind: ExitOrderKind::from((string) $model->kind),
            reason: ExitReason::from((string) $model->reason),
            status: ExitOrderStatus::from((string) $model->status),
            buyerPartnerId: $model->buyer_partner_id,
            exitDate: $model->exit_date->format('Y-m-d'),
            plannedHeadCount: (int) $model->planned_head_count,
            animals: $model->animals->map(fn (ExitOrderAnimal $line) => self::lineToEntity($line))->values()->all(),
            requestedByUserId: $model->requested_by_user_id,
            emittedAt: $model->emitted_at,
            firstExecutedAt: $model->first_executed_at,
            closedAt: $model->closed_at,
            responsable: $model->responsable,
            observations: $model->observations,
            closingReason: $model->closing_reason,
            dtes: $model->relationLoaded('dtes')
                ? $model->dtes->map(fn (ExitOrderDte $dte) => new ExitOrderDteEntity(
                    (int) $dte->id,
                    (string) $dte->dte_number,
                    $dte->dte_date->format('Y-m-d'),
                    (int) $dte->head_count,
                    $dte->loaded_by_user_id,
                    $dte->observations,
                    $dte->created_at,
                ))->values()->all()
                : [],
            history: $model->relationLoaded('history')
                ? $model->history->map(fn (ExitOrderHistory $row) => new ExitOrderHistoryEntity(
                    (int) $row->id,
                    $row->from_status,
                    (string) $row->to_status,
                    $row->action_user_id,
                    $row->actionUser?->name,
                    $row->action_reason,
                    $row->action_metadata,
                    $row->created_at,
                ))->values()->all()
                : [],
            buyerName: $model->buyer?->name,
            buyerCuit: $model->buyer?->cuit,
        );
    }

    private static function lineToEntity(ExitOrderAnimal $line): ExitOrderAnimalEntity
    {
        $caravan = $line->relationLoaded('caravan') ? $line->caravan : null;
        $status = ExitOrderAnimalStatus::from((string) $line->status);

        return new ExitOrderAnimalEntity(
            id: (int) $line->id,
            caravanId: (int) $line->caravan_id,
            status: $status,
            snapshot: $status === ExitOrderAnimalStatus::EXITED
                ? new ExitSnapshot(
                    $line->from_batch_id,
                    $line->activity_id,
                    $line->category_id,
                    (bool) $line->was_pregnant,
                    $line->relationLoaded('fromBatch') ? $line->fromBatch?->name : null,
                    $line->relationLoaded('activity') ? $line->activity?->name : null,
                    $line->relationLoaded('category') ? $line->category?->name : null,
                )
                : null,
            weightKg: $line->weight_kg,
            pricePerKg: $line->price_per_kg,
            amount: $line->amount,
            exitedAt: $line->exited_at?->format('Y-m-d'),
            caravanMovementId: $line->caravan_movement_id,
            caravanWeightId: $line->caravan_weight_id,
            skippedReason: $line->skipped_reason,
            label: $caravan !== null
                ? AnimalLabel::of($caravan->identification, $caravan->lineage?->mother?->identification, $caravan->sex instanceof \BackedEnum ? $caravan->sex->value : $caravan->sex)
                : null,
        );
    }
}
