<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * What a warehouse holds of one item, as the consumption source planner reads it.
 */
final readonly class WarehouseBalance
{
    public function __construct(
        public int $warehouseId,
        public string $code,
        public string $name,
        public Decimal $balance,
        public Decimal $unvalued,
        public Decimal $average,
        public ?Decimal $critical = null,
    ) {
    }

    /** A transfer moves only valued units and never leaves the origin below zero (DD8). */
    public function transferable(): Decimal
    {
        $valued = $this->balance->sub($this->unvalued);

        return $valued->isPositive() ? $valued : Decimal::zero();
    }
}
