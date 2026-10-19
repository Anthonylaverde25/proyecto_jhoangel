<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockIncidentEntity;
use App\Core\Enums\StockIncidentStatus;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\IStockIncidentRepository;
use App\Core\Interfaces\IUnvaluedLotRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class GetItemStockUseCase
{
    public function __construct(
        private readonly IItemRepository $items,
        private readonly IItemStockRepository $stocks,
        private readonly IStockIncidentRepository $incidents,
        private readonly IUnvaluedLotRepository $lots,
    ) {
    }

    public function __invoke(int $itemId, int $companyId): ItemStockView
    {
        $item = $this->items->findById($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} not found.");

        $incidents = $this->incidents->search($companyId, StockIncidentStatus::OPEN, $itemId);
        $noticed = array_map(fn (StockIncidentEntity $i) => $i->getWarehouseId(), $incidents);
        // An inactive warehouse shows while it still holds something of the item (DD4).
        $stocks = array_values(array_filter(
            $this->stocks->findByItem($itemId, $companyId),
            fn (ItemStockEntity $s) => $s->isWarehouseActive()
                || bccomp($s->getQuantity(), '0', 4) !== 0
                || bccomp($s->getUnvaluedQuantity(), '0', 4) !== 0
                || in_array($s->getWarehouseId(), $noticed, true),
        ));

        return new ItemStockView($item, $stocks, $incidents, $this->lots->pendingOfItem($itemId, $companyId));
    }
}
