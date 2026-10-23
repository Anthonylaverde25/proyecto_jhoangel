<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Core\Interfaces\ISemenInventoryReader;

/** Lots of an item with what each holds in a tank, for the lot pickers (SL5). */
final class ListSemenLotsUseCase
{
    public function __construct(
        private readonly ISemenInventoryReader $reader,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(int $itemId, int $warehouseId, int $companyId): array
    {
        return $this->reader->lotsInWarehouse($itemId, $warehouseId, $companyId);
    }
}
