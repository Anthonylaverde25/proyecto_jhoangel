<?php

declare(strict_types=1);

namespace App\Core\Services;

/**
 * The weight curve of each category of a batch.
 *
 * Each point is the average, on that day, of the latest weight of each animal of the category. With
 * the plain average of what was weighed that day, re-weighing one cow would draw the whole category
 * at her weight. The last point of a category is therefore its current average.
 *
 * The readings belong to the animals in the batch today, so an animal moving in or out never bends
 * a curve, and a cow's average never mixes with a calf's: both effects stay out by construction.
 */
final class CategoryWeightSeries
{
    /**
     * @param list<array{caravan_id: int, category_id: ?int, category_name: ?string, weight: float, date: string}> $readings oldest first
     * @param array<string, int> $headsByCategory category key (see key()) => head of the category today
     * @return list<array{weighing_date: string, category_id: ?int, category_name: ?string, average_weight: float, weighed_count: int, head_count: int}>
     *         one row per day a category was weighed, oldest first
     */
    public function build(array $readings, array $headsByCategory): array
    {
        $latest = [];
        $series = [];
        $byDay = [];

        foreach ($readings as $reading) {
            $byDay[$reading['date']][] = $reading;
        }

        foreach ($byDay as $date => $dayReadings) {
            $weighedToday = [];

            foreach ($dayReadings as $reading) {
                $key = self::key($reading['category_id']);
                $latest[$key][$reading['caravan_id']] = $reading['weight'];
                $weighedToday[$key] = $reading;
            }

            foreach ($weighedToday as $key => $reading) {
                $weights = $latest[$key];

                $series[] = [
                    'weighing_date' => (string) $date,
                    'category_id' => $reading['category_id'],
                    'category_name' => $reading['category_name'],
                    'average_weight' => round(array_sum($weights) / count($weights), 2),
                    'weighed_count' => count($weights),
                    'head_count' => $headsByCategory[$key] ?? count($weights),
                ];
            }
        }

        return $series;
    }

    /** A null category stands for the animals with none on record, and they are one group too. */
    public static function key(?int $categoryId): string
    {
        return (string) ($categoryId ?? 'none');
    }
}
