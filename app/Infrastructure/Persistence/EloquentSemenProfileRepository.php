<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\SemenMapper;
use App\Core\Entities\SemenProfileEntity;
use App\Core\Enums\SemenType;
use App\Core\Interfaces\ISemenProfileRepository;
use App\Models\ItemSemenDetail;
use Illuminate\Database\Eloquent\Builder;

/** Every query filters by company explicitly (D15, layer 2). */
class EloquentSemenProfileRepository implements ISemenProfileRepository
{
    public function findByItem(int $itemId, int $companyId): ?SemenProfileEntity
    {
        $model = $this->query($companyId)->with('sire.breed', 'sire.caravan')->find($itemId);

        return $model ? SemenMapper::profileToEntity($model) : null;
    }

    public function findBySireAndType(int $sireId, SemenType $type, int $companyId): ?SemenProfileEntity
    {
        $model = $this->query($companyId)->where('semen_sire_id', $sireId)->where('semen_type', $type->value)->first();

        return $model ? SemenMapper::profileToEntity($model) : null;
    }

    public function save(SemenProfileEntity $profile): SemenProfileEntity
    {
        ItemSemenDetail::withoutGlobalScopes()->updateOrCreate(
            ['item_id' => $profile->itemId],
            [
                'company_id' => $profile->companyId,
                'semen_sire_id' => $profile->sireId,
                'semen_type' => $profile->type->value,
                'preservation' => $profile->preservation->value,
                'straw_size' => $profile->strawSize?->value,
                'straw_color' => $profile->strawColor?->value,
                'cap_color' => $profile->capColor?->value,
            ],
        );

        return $this->findByItem($profile->itemId, $profile->companyId) ?? $profile;
    }

    public function forItems(array $itemIds, int $companyId): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $this->query($companyId)
            ->with('sire.breed', 'sire.caravan')
            ->whereIn('item_id', $itemIds)
            ->get()
            ->mapWithKeys(fn (ItemSemenDetail $model) => [(int) $model->item_id => SemenMapper::profileToEntity($model)])
            ->all();
    }

    private function query(int $companyId): Builder
    {
        return ItemSemenDetail::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
