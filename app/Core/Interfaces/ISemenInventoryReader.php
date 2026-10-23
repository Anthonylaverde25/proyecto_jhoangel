<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/** Read model of the semen stock (plan 4.7 and 5.7): plain arrays for the screens. */
interface ISemenInventoryReader
{
    /** Every semen item with its profile, balance per tank, lots and straws without lot. @return list<array<string, mixed>> */
    public function inventory(int $companyId): array;

    /** Lots of an item with what each holds in the tank, for the pickers (SL5). @return list<array<string, mixed>> */
    public function lotsInWarehouse(int $itemId, int $warehouseId, int $companyId): array;

    /** A lot with its balances, entries, movements and identifications. @return array<string, mixed>|null */
    public function lotDetail(int $lotId, int $companyId): ?array;
}
