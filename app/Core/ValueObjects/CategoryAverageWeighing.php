<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Exceptions\WeighingOrderDomainException;

/**
 * The average per head of one category of a batch, and the heads it covers. A null category stands
 * for the animals of the batch with no category on record, weighed as their own group.
 */
final readonly class CategoryAverageWeighing
{
    private const MAX_AVERAGE_KG = 1200.0;

    /**
     * @throws WeighingOrderDomainException
     */
    public function __construct(
        public ?int $categoryId,
        public int $headCount,
        public float $averageWeight,
        public ?string $categoryName = null,
    ) {
        if ($headCount < 1) {
            throw WeighingOrderDomainException::domainError('Indicá cuántas cabezas se pesaron.', 'AVERAGE_WITHOUT_HEADS');
        }

        if ($averageWeight <= 0 || $averageWeight > self::MAX_AVERAGE_KG) {
            throw WeighingOrderDomainException::domainError(
                'El promedio tiene que estar entre 0 y ' . (int) self::MAX_AVERAGE_KG . " kg/cab (se recibió {$averageWeight}).",
                'AVERAGE_OUT_OF_RANGE'
            );
        }
    }

    public function netWeight(): float
    {
        return $this->averageWeight * $this->headCount;
    }
}
