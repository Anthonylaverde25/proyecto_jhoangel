<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\SemenLotEntity;
use App\Core\Entities\SemenProfileEntity;
use App\Core\Entities\SemenSireEntity;
use App\Core\Entities\SemenUnidentifiedEntryEntity;
use App\Core\Enums\SemenPreservation;
use App\Core\Enums\SemenType;
use App\Core\Enums\StrawColor;
use App\Core\Enums\StrawSize;
use App\Models\ItemSemenDetail;
use App\Models\SemenLot;
use App\Models\SemenSire;
use App\Models\SemenUnidentifiedEntry;

final class SemenMapper
{
    public static function sireToEntity(SemenSire $model, int $activeItems = 0): SemenSireEntity
    {
        $caravan = $model->relationLoaded('caravan') ? $model->caravan : null;

        return new SemenSireEntity(
            (int) $model->id,
            (int) $model->company_id,
            $model->name,
            $model->registry_code,
            $model->breed_id,
            $model->stud_name,
            $model->caravan_id,
            $model->notes,
            (bool) $model->is_active,
            $model->relationLoaded('breed') ? $model->breed?->name : null,
            $caravan?->identification,
            $activeItems,
        );
    }

    public static function profileToEntity(ItemSemenDetail $model): SemenProfileEntity
    {
        $sire = $model->relationLoaded('sire') && $model->sire !== null ? self::sireToEntity($model->sire) : null;

        return new SemenProfileEntity(
            (int) $model->item_id,
            (int) $model->company_id,
            (int) $model->semen_sire_id,
            SemenType::from($model->semen_type),
            SemenPreservation::from($model->preservation),
            $model->straw_size === null ? null : StrawSize::from($model->straw_size),
            $model->straw_color === null ? null : StrawColor::from($model->straw_color),
            $model->cap_color === null ? null : StrawColor::from($model->cap_color),
            $sire,
        );
    }

    public static function lotToEntity(SemenLot $model): SemenLotEntity
    {
        return new SemenLotEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->item_id,
            $model->code,
            $model->collection_date?->toDateString(),
            $model->collection_code,
            $model->notes,
        );
    }

    public static function unidentifiedToEntity(SemenUnidentifiedEntry $model): SemenUnidentifiedEntryEntity
    {
        return new SemenUnidentifiedEntryEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->item_id,
            (int) $model->warehouse_id,
            (int) $model->item_document_line_id,
            $model->stock_incident_id,
            (int) $model->direction,
            DecimalColumn::read($model->quantity, 4),
            DecimalColumn::read($model->identified_quantity ?? '0', 4),
            (string) $model->status,
        );
    }
}
