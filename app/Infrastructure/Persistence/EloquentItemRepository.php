<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\ItemMapper;
use App\Core\Entities\ItemEntity;
use App\Core\Entities\ItemPresentationEntity;
use App\Core\Entities\ItemProviderLinkEntity;
use App\Core\Interfaces\IItemRepository;
use App\Core\ValueObjects\ItemFilters;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemPresentation;
use App\Models\ItemProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every query filters by company explicitly (D15, layer 2): it may run without a request context.
 */
class EloquentItemRepository implements IItemRepository
{
    private const CODE_DIGITS = 4;

    public function search(ItemFilters $filters, int $companyId): array
    {
        $categories = $this->categoryIndex($companyId);

        $query = $this->query($companyId)
            ->with('unit')
            ->withCount(['presentations', 'providerLinks'])
            ->when(!$filters->includeInactive, fn (Builder $q) => $q->where('is_active', true))
            ->when($filters->behavior !== null, function (Builder $q) use ($filters) {
                $q->where('type', $filters->behavior->type()->value)
                    ->where('track_stock', $filters->behavior->tracksStock());
            })
            ->when($filters->categoryId !== null, function (Builder $q) use ($filters, $categories) {
                $q->whereIn('item_category_id', $this->withDescendants($filters->categoryId, $categories));
            })
            ->when($filters->search !== null, function (Builder $q) use ($filters) {
                $term = '%' . $filters->search . '%';
                $q->where(fn (Builder $w) => $w->where('code', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('name');

        return $query->get()
            ->map(fn (Item $model) => $this->toEntity($model, $categories))
            ->all();
    }

    public function findById(int $id, int $companyId): ?ItemEntity
    {
        $model = $this->query($companyId)
            ->with(['unit', 'presentations', 'providerLinks.provider'])
            ->find($id);

        return $model ? $this->toEntity($model, $this->categoryIndex($companyId)) : null;
    }

    public function findByCode(string $code, int $companyId): ?ItemEntity
    {
        $model = $this->query($companyId)->where('code', $code)->first();

        return $model ? ItemMapper::toEntity($model) : null;
    }

    public function save(ItemEntity $item): ItemEntity
    {
        $model = $item->getId() !== null ? $this->query($item->getCompanyId())->findOrFail($item->getId()) : null;
        $model = ItemMapper::toModel($item, $model);
        $model->save();

        return $this->findById($model->id, $item->getCompanyId());
    }

    /**
     * Two simultaneous creations may read the same highest code; the unique key turns the second
     * one away and it takes the next number. One retry is enough for the volume of item creation.
     */
    public function saveWithGeneratedCode(ItemEntity $item): ItemEntity
    {
        for ($attempt = 0; ; $attempt++) {
            $item->assignCode($this->nextCode($item->getType()->codePrefix(), $item->getCompanyId()));

            try {
                return DB::transaction(fn () => $this->save($item));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 1) {
                    throw $e;
                }
            }
        }
    }

    public function findPresentation(int $presentationId, int $itemId): ?ItemPresentationEntity
    {
        $model = ItemPresentation::query()->where('item_id', $itemId)->find($presentationId);

        return $model ? ItemMapper::presentationToEntity($model) : null;
    }

    public function presentationNameExists(int $itemId, string $name, ?int $exceptId = null): bool
    {
        return ItemPresentation::query()
            ->where('item_id', $itemId)
            ->where('name', $name)
            ->when($exceptId !== null, fn (Builder $q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    public function savePresentation(ItemPresentationEntity $presentation): ItemPresentationEntity
    {
        $model = $presentation->getId() !== null
            ? ItemPresentation::query()->where('item_id', $presentation->getItemId())->findOrFail($presentation->getId())
            : new ItemPresentation(['item_id' => $presentation->getItemId()]);

        $model->name = $presentation->getName();
        $model->factor_to_item_unit = $presentation->getFactorToItemUnit();
        $model->is_active = $presentation->isActive();
        $model->save();

        return ItemMapper::presentationToEntity($model);
    }

    public function syncProviderLinks(int $itemId, int $companyId, array $links): void
    {
        DB::transaction(function () use ($itemId, $companyId, $links) {
            ItemProvider::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('item_id', $itemId)
                ->delete();

            foreach ($links as $link) {
                /** @var ItemProviderLinkEntity $link */
                ItemProvider::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'item_id' => $itemId,
                    'provider_id' => $link->getProviderId(),
                    'lead_time_days' => $link->getLeadTimeDays(),
                    'is_preferred' => $link->isPreferred(),
                ]);
            }
        });
    }

    public function countLinksOfProvider(int $providerId, int $companyId): int
    {
        return ItemProvider::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('provider_id', $providerId)
            ->count();
    }

    private function nextCode(string $prefix, int $companyId): string
    {
        $highest = $this->query($companyId)
            ->where('code', 'like', $prefix . '-%')
            ->pluck('code')
            ->map(fn (string $code) => preg_match('/^' . $prefix . '-(\d+)$/', $code, $m) === 1 ? (int) $m[1] : 0)
            ->max() ?? 0;

        return sprintf('%s-%0' . self::CODE_DIGITS . 'd', $prefix, $highest + 1);
    }

    /**
     * One query for the whole tree, so neither the path nor the descendants cost a query per row.
     *
     * @return array<int, array{name:string, parent:?int}>
     */
    private function categoryIndex(int $companyId): array
    {
        return ItemCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'parent_id'])
            ->mapWithKeys(fn (ItemCategory $c) => [$c->id => ['name' => $c->name, 'parent' => $c->parent_id]])
            ->all();
    }

    /**
     * @param array<int, array{name:string, parent:?int}> $categories
     * @return list<int>
     */
    private function withDescendants(int $categoryId, array $categories): array
    {
        $ids = [$categoryId];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($categories as $id => $category) {
                if ($category['parent'] === $ids[$i] && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @param array<int, array{name:string, parent:?int}> $categories */
    private function toEntity(Item $model, array $categories): ItemEntity
    {
        $categoryId = $model->item_category_id;
        if ($categoryId === null || !isset($categories[$categoryId])) {
            return ItemMapper::toEntity($model);
        }

        $names = [];
        for ($cursor = $categoryId, $guard = count($categories); $cursor !== null && $guard-- >= 0; $cursor = $categories[$cursor]['parent'] ?? null) {
            if (!isset($categories[$cursor])) {
                break;
            }
            array_unshift($names, $categories[$cursor]['name']);
        }

        return ItemMapper::toEntity($model, $categories[$categoryId]['name'], implode(' › ', $names));
    }

    private function query(int $companyId): Builder
    {
        return Item::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
