<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Core\Interfaces\ISemenInventoryReader;

/** The semen tab (plan 4.7): one row per sire and type, its lots per tank and what is still without lot. */
final class GetSemenInventoryUseCase
{
    public function __construct(
        private readonly ISemenInventoryReader $reader,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(int $companyId, ?int $warehouseId, bool $withBalance, bool $includeInactive): array
    {
        $rows = [];
        foreach ($this->reader->inventory($companyId) as $row) {
            if (!$includeInactive && !$row['item']['is_active']) {
                continue;
            }
            $stocks = $warehouseId === null ? $row['stocks'] : array_values(array_filter($row['stocks'], fn (array $s) => $s['warehouse']['id'] === $warehouseId));
            if ($withBalance && $stocks === [] && $row['unidentified'] === []) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
