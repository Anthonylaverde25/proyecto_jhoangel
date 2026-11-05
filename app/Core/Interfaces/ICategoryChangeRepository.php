<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\CategoryChangeEntity;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;

interface ICategoryChangeRepository
{
    /**
     * Records an immutable category change event.
     */
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
    ): CategoryChangeEntity;

    /**
     * Returns category changes for a caravan from newest to oldest.
     *
     * @return list<CategoryChangeEntity>
     */
    public function listByCaravan(int $caravanId): array;

    /**
     * Returns the latest category change recorded for a caravan.
     */
    public function latestFor(int $caravanId): ?CategoryChangeEntity;
}

