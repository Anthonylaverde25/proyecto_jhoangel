<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\ValueObjects\BatchWeighingMeasure;
use App\Core\ValueObjects\CategoryAverageWeighing;

/**
 * Turns a batch weighing into a weight per caravan.
 *
 * Nobody recorded which animals went on the scale, so the only defensible assignment is the
 * average, and only when the weighing covers the whole category: with 55 of 60 cows weighed,
 * assigning the average to all 60 would state a weight for five animals nobody put on the scale.
 * Each average stays inside its category, because a cow's average would falsify a calf; a category
 * left out keeps its weights. The mass of each category after imputing is its net mass.
 */
final class GroupWeightImputation
{
    /**
     * @param array<int, ?int> $categoryByCaravan the animals in possession in the batch: caravan id => category id
     * @param array<int, string> $categoryNames category id => name, for the message when the heads do not match
     * @return array<int, float> weight by caravan id, only for the categories weighed
     *
     * @throws WeighingOrderDomainException
     */
    public function impute(BatchWeighingMeasure $measure, array $categoryByCaravan, array $categoryNames = []): array
    {
        $weights = [];

        foreach ($measure->averagesByCategory() as $average) {
            $caravanIds = array_keys(array_filter(
                $categoryByCaravan,
                static fn (?int $categoryId): bool => $categoryId === $average->categoryId
            ));

            if (count($caravanIds) !== $average->headCount) {
                throw $this->coverageMismatch($measure, $average, count($caravanIds), $categoryNames[$average->categoryId ?? 0] ?? null);
            }

            $weights += array_fill_keys($caravanIds, round($average->averageWeight, 2));
        }

        return $weights;
    }

    private function coverageMismatch(BatchWeighingMeasure $measure, CategoryAverageWeighing $average, int $inBatch, ?string $name): WeighingOrderDomainException
    {
        $heads = $average->headCount;
        $difference = abs($inBatch - $heads);
        $source = $measure->loads !== [] ? 'Las jaulas suman' : 'El pesaje declara';
        $of = $name !== null ? " de {$name}" : '';
        $hint = $heads < $inBatch
            ? "Faltan {$difference}: pesalas o revisá el conteo."
            : "Sobran {$difference}: revisá el conteo.";

        return WeighingOrderDomainException::domainError(
            "{$source} {$heads} cabezas{$of} y el lote tiene {$inBatch}. {$hint}",
            'GROUP_COVERAGE_MISMATCH'
        );
    }
}
