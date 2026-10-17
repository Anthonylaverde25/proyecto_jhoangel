<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\ValueObjects\BatchWeighingMeasure;

/**
 * Turns a batch weighing into a weight per caravan.
 *
 * Nobody recorded which animals went on the scale, so the only defensible assignment is the
 * average, and only when the weighing covers the whole batch: with 55 of 60 head weighed,
 * assigning the average to all 60 would state a weight for five animals nobody put on the scale.
 * The mass of the batch after imputing is the net mass of the weighing.
 */
final class GroupWeightImputation
{
    /**
     * @param int[] $caravanIds the animals in possession in the batch
     * @return array<int, float> weight by caravan id
     *
     * @throws WeighingOrderDomainException
     */
    public function impute(BatchWeighingMeasure $measure, array $caravanIds): array
    {
        $heads = $measure->headCount;
        $batchHeads = count($caravanIds);

        if ($heads !== $batchHeads) {
            $difference = abs($batchHeads - $heads);
            $source = $measure->loads !== [] ? 'Las jaulas suman' : 'El pesaje declara';
            $hint = $heads < $batchHeads
                ? "Faltan {$difference}: pesalas o revisá el conteo."
                : "Sobran {$difference}: revisá el conteo.";

            throw WeighingOrderDomainException::domainError(
                "{$source} {$heads} cabezas y el lote tiene {$batchHeads}. {$hint}",
                'GROUP_COVERAGE_MISMATCH'
            );
        }

        return array_fill_keys($caravanIds, round($measure->averageWeight(), 2));
    }
}
