<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Enums\StockIncidentStatus;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\IStockIncidentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class GetItemStockUseCase
{
    public function __construct(
        private readonly IItemRepository $items,
        private readonly IItemStockRepository $stocks,
        private readonly IStockIncidentRepository $incidents,
    ) {
    }

    public function __invoke(int $itemId, int $companyId): ItemStockView
    {
        $item = $this->items->findById($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} not found.");

        return new ItemStockView(
            $item,
            $this->stocks->findByItem($itemId, $companyId),
            $this->incidents->search($companyId, StockIncidentStatus::OPEN, $itemId),
        );
    }
}
