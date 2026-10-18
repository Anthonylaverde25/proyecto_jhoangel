<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Entities\ItemEntity;
use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockIncidentEntity;

/**
 * The Stock tab of an item: its balance in each warehouse and its open notices.
 */
final readonly class ItemStockView
{
    /**
     * @param list<ItemStockEntity> $stocks
     * @param list<StockIncidentEntity> $openIncidents
     */
    public function __construct(
        public ItemEntity $item,
        public array $stocks,
        public array $openIncidents,
    ) {
    }
}
