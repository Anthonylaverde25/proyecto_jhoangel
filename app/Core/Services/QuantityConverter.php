<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\ItemPresentationEntity;
use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\ValueObjects\Decimal;

/**
 * Turns what the paper says (a quantity in a unit or in a presentation of the item) into the item's
 * unit (D16, I17). Returns null when the unit does not convert, so the caller reports it on the
 * right field.
 */
final class QuantityConverter
{
    public const QUANTITY_SCALE = 4;

    public function toItemUnit(
        Decimal $inputQuantity,
        UnitOfMeasureEntity $itemUnit,
        ?UnitOfMeasureEntity $inputUnit,
        ?ItemPresentationEntity $presentation,
    ): ?Decimal {
        if ($presentation !== null) {
            return $inputQuantity->mul(Decimal::of($presentation->getFactorToItemUnit()))->round(self::QUANTITY_SCALE);
        }
        if ($inputUnit === null) {
            return null;
        }
        if ($inputUnit->getId() === $itemUnit->getId()) {
            return $inputQuantity->round(self::QUANTITY_SCALE);
        }
        if (!$this->convertible($inputUnit, $itemUnit)) {
            return null;
        }

        return $inputQuantity
            ->mul(Decimal::of((string) $inputUnit->getFactorToBase()))
            ->div(Decimal::of((string) $itemUnit->getFactorToBase()))
            ->round(self::QUANTITY_SCALE);
    }

    public function convertible(UnitOfMeasureEntity $from, UnitOfMeasureEntity $to): bool
    {
        return $from->getId() === $to->getId()
            || ($from->isConvertible() && $to->isConvertible() && $from->getDimension() === $to->getDimension());
    }
}
