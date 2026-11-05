<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\BatchWeighingEntry;
use App\Core\Exceptions\WeighingOrderDomainException;

/**
 * What the scale said about a batch: the loads one by one, or the average of each category weighed.
 * All reduce to the same two facts, heads and net mass; the entry is kept so the order records what
 * was actually written down, not a load nobody weighed.
 */
final readonly class BatchWeighingMeasure
{
    private const MAX_AVERAGE_KG = 1200.0;

    /**
     * @param CageGroupWeighing[] $loads
     * @param CategoryAverageWeighing[] $categoryAverages
     */
    private function __construct(
        public BatchWeighingEntry $entry,
        public int $headCount,
        public float $netWeight,
        public array $loads = [],
        public array $categoryAverages = [],
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
     * @param CategoryAverageWeighing[] $averages
     *
     * @throws WeighingOrderDomainException
     */
    public static function fromCategoryAverages(array $averages): self
    {
        if ($averages === []) {
            throw WeighingOrderDomainException::domainError('Cargá el promedio de al menos una categoría.', 'NO_CATEGORY_AVERAGES');
        }

        $categories = array_map(static fn (CategoryAverageWeighing $a): ?int => $a->categoryId, $averages);
        if (count($categories) !== count(array_unique($categories, SORT_REGULAR))) {
            throw WeighingOrderDomainException::domainError('Cada categoría se carga una sola vez.', 'DUPLICATE_CATEGORY_AVERAGE');
        }

        $heads = array_sum(array_map(static fn (CategoryAverageWeighing $a): int => $a->headCount, $averages));
        $net = array_sum(array_map(static fn (CategoryAverageWeighing $a): float => $a->netWeight(), $averages));

        return new self(BatchWeighingEntry::BY_CATEGORY, $heads, $net, categoryAverages: array_values($averages));
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

    /**
     * The average each category weighed receives, whichever way it was written down. Several loads
     * of one category make one average, weighted by the heads of each load.
     *
     * @return CategoryAverageWeighing[]
     *
     * @throws WeighingOrderDomainException
     */
    public function averagesByCategory(): array
    {
        if ($this->entry !== BatchWeighingEntry::LOADS) {
            return $this->categoryAverages;
        }

        $byCategory = [];
        foreach ($this->loads as $load) {
            $byCategory[$load->categoryId ?? 0][] = $load;
        }

        return array_map(static function (array $loads): CategoryAverageWeighing {
            $heads = array_sum(array_map(static fn (CageGroupWeighing $l): int => $l->headCount, $loads));
            $net = array_sum(array_map(static fn (CageGroupWeighing $l): float => $l->netWeight(), $loads));

            return new CategoryAverageWeighing($loads[0]->categoryId, $heads, $net / $heads, $loads[0]->categoryName);
        }, array_values($byCategory));
    }

    public function averageWeight(): float
    {
        return $this->netWeight / $this->headCount;
    }
}
