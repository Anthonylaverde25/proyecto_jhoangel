<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CategoryChangeEntity;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;
use App\Core\Interfaces\ICategoryChangeRepository;
use App\Models\CaravanCategoryChange;

final class EloquentCategoryChangeRepository implements ICategoryChangeRepository
{
    public function record(
        int $companyId,
        int $caravanId,
        ?int $fromCategoryId,
        ?int $fromSubcategoryId,
        ?int $toCategoryId,
        ?int $toSubcategoryId,
        CategoryChangeCause $cause,
        string $occurredOn,
        ?CategoryChangeSource $sourceType,
        ?int $sourceId,
        ?int $userId
    ): CategoryChangeEntity {
        /** @var CaravanCategoryChange $row */
        $row = CaravanCategoryChange::create([
            'company_id' => $companyId,
            'caravan_id' => $caravanId,
            'from_category_id' => $fromCategoryId,
            'from_subcategory_id' => $fromSubcategoryId,
            'to_category_id' => $toCategoryId,
            'to_subcategory_id' => $toSubcategoryId,
            'cause' => $cause->value,
            'occurred_on' => $occurredOn,
            'source_type' => $sourceType?->value,
            'source_id' => $sourceId,
            'registered_by_user_id' => $userId,
        ]);

        $row->load(['fromCategory', 'fromSubcategory', 'toCategory', 'toSubcategory', 'registeredBy']);

        return $this->toEntity($row);
    }

    public function listByCaravan(int $caravanId): array
    {
        return CaravanCategoryChange::withoutGlobalScopes()
            ->where('caravan_id', $caravanId)
            ->with(['fromCategory', 'fromSubcategory', 'toCategory', 'toSubcategory', 'registeredBy'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CaravanCategoryChange $row) => $this->toEntity($row))
            ->all();
    }

    public function latestFor(int $caravanId): ?CategoryChangeEntity
    {
        /** @var CaravanCategoryChange|null $row */
        $row = CaravanCategoryChange::withoutGlobalScopes()
            ->where('caravan_id', $caravanId)
            ->with(['fromCategory', 'fromSubcategory', 'toCategory', 'toSubcategory', 'registeredBy'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->first();

        return $row !== null ? $this->toEntity($row) : null;
    }

    private function toEntity(CaravanCategoryChange $row): CategoryChangeEntity
    {
        return new CategoryChangeEntity(
            id: (int) $row->id,
            companyId: (int) $row->company_id,
            caravanId: (int) $row->caravan_id,
            fromCategoryId: $row->from_category_id ? (int) $row->from_category_id : null,
            fromCategoryCode: $row->fromCategory?->code,
            fromCategoryName: $row->fromCategory?->name,
            fromSubcategoryId: $row->from_subcategory_id ? (int) $row->from_subcategory_id : null,
            fromSubcategoryName: $row->fromSubcategory?->name,
            toCategoryId: $row->to_category_id ? (int) $row->to_category_id : null,
            toCategoryCode: $row->toCategory?->code,
            toCategoryName: $row->toCategory?->name,
            toSubcategoryId: $row->to_subcategory_id ? (int) $row->to_subcategory_id : null,
            toSubcategoryName: $row->toSubcategory?->name,
            cause: CategoryChangeCause::from($row->cause),
            occurredOn: $row->occurred_on instanceof \DateTimeInterface ? $row->occurred_on->format('Y-m-d') : substr((string) $row->occurred_on, 0, 10),
            sourceType: $row->source_type ? CategoryChangeSource::tryFrom($row->source_type) : null,
            sourceId: $row->source_id ? (int) $row->source_id : null,
            registeredByUserId: $row->registered_by_user_id ? (int) $row->registered_by_user_id : null,
            registeredByUserName: $row->registeredBy?->name,
            createdAt: $row->created_at?->format('Y-m-d H:i:s')
        );
    }
}

