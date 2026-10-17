<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\BatchWeighingEntry;
use App\Core\Exceptions\WeighingOrderDomainException;

/**
 * What the scale said about a batch: either the loads one by one, or the average already worked
 * out with the heads it covers. Both reduce to the same two facts, heads and net mass; the entry
 * is kept so the order records what was actually written down, not a load nobody weighed.
 */
final readonly class BatchWeighingMeasure
{
    private const MAX_AVERAGE_KG = 1200.0;

    /**
     * @param CageGroupWeighing[] $loads
     */
    private function __construct(
        public BatchWeighingEntry $entry,
        public int $headCount,
        public float $netWeight,
        public array $loads = [],
    ) {
    }

    /**
     * @param CageGroupWeighing[] $loads
     *
     * @throws WeighingOrderDomainException
     */
    public static function fromLoads(array $loads): self
    {
        if ($loads === []) {
            throw WeighingOrderDomainException::domainError('Cargá al menos una jaula.', 'NO_GROUPS');
        }

        $heads = array_sum(array_map(static fn (CageGroupWeighing $g): int => $g->headCount, $loads));
        $net = array_sum(array_map(static fn (CageGroupWeighing $g): float => $g->netWeight(), $loads));

        return new self(BatchWeighingEntry::LOADS, $heads, $net, array_values($loads));
    }

    /**
     * @throws WeighingOrderDomainException
     */
    public static function fromAverage(int $headCount, float $averageWeight): self
    {
        if ($headCount < 1) {
            throw WeighingOrderDomainException::domainError('Indicá cuántas cabezas se pesaron.', 'AVERAGE_WITHOUT_HEADS');
        }

        if ($averageWeight <= 0 || $averageWeight > self::MAX_AVERAGE_KG) {
            throw WeighingOrderDomainException::domainError(
                "El promedio tiene que estar entre 0 y " . (int) self::MAX_AVERAGE_KG . " kg/cab (se recibió {$averageWeight}).",
                'AVERAGE_OUT_OF_RANGE'
            );
        }

        return new self(BatchWeighingEntry::AVERAGE, $headCount, $averageWeight * $headCount);
    }

    public function averageWeight(): float
    {
        return $this->netWeight / $this->headCount;
    }
}
