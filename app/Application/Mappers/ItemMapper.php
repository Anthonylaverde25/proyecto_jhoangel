<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\ItemEntity;
use App\Core\Entities\ItemPresentationEntity;
use App\Core\Entities\ItemProviderLinkEntity;
use App\Core\Enums\ItemType;
use App\Models\Item;
use App\Models\ItemPresentation;
use App\Models\ItemProvider;

class ItemMapper
{
    public static function toEntity(Item $model, ?string $categoryName = null, ?string $categoryPath = null): ItemEntity
    {
        $presentations = $model->relationLoaded('presentations')
            ? $model->presentations->map(fn (ItemPresentation $p) => self::presentationToEntity($p))->values()->all()
            : [];

        $links = $model->relationLoaded('providerLinks')
            ? $model->providerLinks->map(fn (ItemProvider $link) => new ItemProviderLinkEntity(
                (int) $link->provider_id,
                $link->lead_time_days,
                (bool) $link->is_preferred,
                $link->relationLoaded('provider') ? $link->provider?->name : null,
                $link->relationLoaded('provider') ? $link->provider?->cuit : null,
            ))->values()->all()
            : [];

        return new ItemEntity(
            $model->id,
            (int) $model->company_id,
            $model->code,
            $model->name,
            $model->description,
            ItemType::from($model->type),
            (bool) $model->track_stock,
            (int) $model->unit_of_measure_id,
            $model->item_category_id !== null ? (int) $model->item_category_id : null,
            (bool) $model->is_active,
            $model->relationLoaded('unit') && $model->unit ? UnitOfMeasureMapper::toEntity($model->unit) : null,
            $categoryName,
            $categoryPath,
            $presentations,
            $links,
            (int) ($model->presentations_count ?? count($presentations)),
            (int) ($model->provider_links_count ?? count($links)),
        );
    }

    public static function toModel(ItemEntity $entity, ?Item $model = null): Item
    {
        $model ??= new Item();

        $model->company_id = $entity->getCompanyId();
        $model->code = $entity->getCode();
        $model->name = $entity->getName();
        $model->description = $entity->getDescription();
        $model->type = $entity->getType()->value;
        $model->track_stock = $entity->tracksStock();
        $model->unit_of_measure_id = $entity->getUnitOfMeasureId();
        $model->item_category_id = $entity->getItemCategoryId();
        $model->is_active = $entity->isActive();

        return $model;
    }

    public static function presentationToEntity(ItemPresentation $model): ItemPresentationEntity
    {
        return new ItemPresentationEntity(
            $model->id,
            (int) $model->item_id,
            $model->name,
            (string) $model->factor_to_item_unit,
            (bool) $model->is_active,
        );
    }
}
