<?php

declare(strict_types=1);

namespace App\Core\Services;

/**
 * Finds the weights of a sheet that stand far from the rest of the same troop.
 *
 * No fixed range: what counts as a normal weight at weaning, or at a change of activity, depends
 * on the breed, the age and the season, and none of that is a number the system holds. The troop
 * itself is the reference — the animals weighed on the same sheet, the same day — so the rule
 * adapts to every load without configuration.
 *
 * The reference is the MEDIAN, not the average: a single 1850 typed for 185 drags the average
 * of a small troop far enough to hide itself, while the median does not move.
 *
 * It flags, it never rejects: a weight this far off is usually a typo or a misreading, but it
 * can be real, and the person reviewing the sheet is the one who decides. Zero and negative
 * weights are not its business — those are never valid and are rejected by the use case.
 *
 * Pure PHP, mirrored on the review screen by `weightOutliers.ts` so the warning shows up BEFORE
 * confirming. The two must keep the same factor and minimum sample.
 */
final class TroopWeightOutlierDetector
{
    /** A weight above twice or below half the troop median is out of place. */
    public const FACTOR = 2.0;

    /** With fewer weighed animals the median describes nothing. */
    public const MIN_SAMPLE = 3;

    /**
     * @param array<int|string, float> $weightsByKey positive weights, keyed by row
     * @return array<int|string, array{weight: float, median: float, above: bool}> only the keys out of place
     */
    public function outliers(array $weightsByKey): array
    {
        $positive = array_filter($weightsByKey, static fn (float $weight): bool => $weight > 0);

        if (count($positive) < self::MIN_SAMPLE) {
            return [];
        }

        $median = $this->median(array_values($positive));
        $outliers = [];

        foreach ($positive as $key => $weight) {
            if ($weight > $median * self::FACTOR || $weight < $median / self::FACTOR) {
                $outliers[$key] = ['weight' => $weight, 'median' => $median, 'above' => $weight > $median];
            }
        }

        return $outliers;
    }

    /**
     * @param list<float> $values non-empty
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
