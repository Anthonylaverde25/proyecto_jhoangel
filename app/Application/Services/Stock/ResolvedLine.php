<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemEntity;
use App\Core\ValueObjects\Decimal;

/**
 * A line whose item, input unit or presentation and quantity in the item's unit are known.
 */
final readonly class ResolvedLine
{
    public function __construct(
        public ItemEntity $item,
        public Decimal $inputQuantity,
        public Decimal $quantity,
        public ?int $inputUnitId,
        public ?int $inputPresentationId,
    ) {
    }
}
