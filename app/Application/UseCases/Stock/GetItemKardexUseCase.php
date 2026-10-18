<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\ValueObjects\KardexFilters;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * An item's movements in load order, newest first (D4), with the balance and average after each.
 */
final class GetItemKardexUseCase
{
    public function __construct(
        private readonly IItemRepository $items,
        private readonly IItemStockRepository $stocks,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function __invoke(int $itemId, KardexFilters $filters, int $companyId): array
    {
        $this->items->findById($itemId, $companyId) ?? throw new ModelNotFoundException("Item {$itemId} not found.");

        return $this->stocks->kardex($itemId, $filters, $companyId);
    }
}
