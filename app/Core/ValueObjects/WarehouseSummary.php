<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * What a warehouse holds, shown before deactivating it (DD4): the user decides with this in view.
 */
final readonly class WarehouseSummary
{
    public function __construct(
        public int $itemsWithBalance = 0,
        public string $valuedValue = '0.0000',
        public int $itemsWithUnvalued = 0,
        public int $pendingLots = 0,
        public int $negativeItems = 0,
        public int $openIncidents = 0,
    ) {
    }
}
