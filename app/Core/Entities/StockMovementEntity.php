<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * Effect of an operation line on a warehouse. Quantity is always positive; direction says whether
 * it entered (+1) or left (-1). The id sequence is the kardex order (D4).
 */
final readonly class StockMovementEntity
{
    public function __construct(
        public int $warehouseId,
        public int $itemId,
        public int $direction,
        public string $quantity,
        public string $unitCost,
        public string $totalCost,
        public string $balanceAfter,
        public string $averageCostAfter,
        public ?int $id = null,
        public ?int $itemDocumentLineId = null,
    ) {
    }
}
