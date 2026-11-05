<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * One way of taking a consumption out of the warehouses: where it leaves from, what is transferred
 * there first, and how it leaves the balance and the cost.
 */
final readonly class ConsumptionSourceOption
{
    /** Everything from the warehouse asked for; its balance may go negative. */
    public const BASE = 'BASE';

    /** The warehouse asked for is completed with transfers from the others, then it leaves from there. */
    public const COMPLETE = 'COMPLETE';

    /** Everything from another warehouse that covers it. */
    public const OTHER = 'OTHER';

    /** @param list<array{from: WarehouseBalance, quantity: Decimal}> $transfers */
    public function __construct(
        public string $code,
        public WarehouseBalance $warehouse,
        public array $transfers,
        public Decimal $balanceAfter,
        public Decimal $estimatedTotal,
        public Decimal $pendingQuantity,
        public bool $costKnown,
    ) {
    }

    public function goesNegative(): bool
    {
        return $this->balanceAfter->isNegative();
    }

    public function fallsBelowCritical(): bool
    {
        return $this->warehouse->critical !== null && $this->balanceAfter->compare($this->warehouse->critical) <= 0;
    }
}
