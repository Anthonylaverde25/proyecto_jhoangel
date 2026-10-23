<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\SemenSireEntity;

/** A semen sire (plan part 3): external, or an own bull with its caravan. */
final class SemenSireResource
{
    /** @return array<string, mixed> */
    public static function shape(SemenSireEntity $sire): array
    {
        return [
            'id' => $sire->id,
            'name' => $sire->name,
            'label' => $sire->label(),
            'registry_code' => $sire->registryCode,
            'breed' => $sire->breedId === null ? null : ['id' => $sire->breedId, 'name' => $sire->breedName],
            'stud_name' => $sire->studName,
            'caravan' => $sire->caravanId === null ? null : ['id' => $sire->caravanId, 'identification' => $sire->caravanTag],
            'is_own_bull' => $sire->isOwnBull(),
            'notes' => $sire->notes,
            'is_active' => $sire->isActive,
            'active_items' => $sire->activeItems,
        ];
    }
}
