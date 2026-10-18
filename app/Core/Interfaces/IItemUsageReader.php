<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * Read side of the "in use" guards that B1 left for B2 (plan 4.10). An item is in use once it has
 * lines in provider documents or in stock operations.
 */
interface IItemUsageReader
{
    public function itemHasLines(int $itemId, int $companyId): bool;

    public function presentationHasLines(int $presentationId): bool;

    /** Lines that use the unit as their input unit. */
    public function unitInputLines(int $unitId, int $companyId): int;

    /** The item's balance where it is not zero, e.g. "4500.0000", or null when zero everywhere. */
    public function nonZeroBalance(int $itemId, int $companyId): ?string;
}
