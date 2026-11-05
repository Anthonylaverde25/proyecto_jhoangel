<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\CaravanWeightEntity;

/**
 * Soft checks for one animal's weight: a strange weight is usually a typo or a misread scale, but
 * it can be real, and the person entering it decides. Nothing here blocks by itself.
 *
 * Shares its physical limits and daily rates with weighingSanity.ts in the SPA.
 */
final class WeighingSanityPolicy
{
    public const MIN_PHYSICAL_KG = 15.0;
    public const MAX_PHYSICAL_KG = 1200.0;
    public const MAX_DAILY_GAIN_KG = 2.5;
    public const MIN_DAILY_GAIN_KG = -1.5;

    private const SECONDS_PER_DAY = 86400;

    /**
     * @param CaravanWeightEntity|null $previous the last weighing dated before this one
     * @return list<string> most important first; empty when nothing is odd
     */
    public function warnings(float $weight, string $date, ?CaravanWeightEntity $previous): array
    {
        $warnings = [];

        if ($weight < self::MIN_PHYSICAL_KG || $weight > self::MAX_PHYSICAL_KG) {
            $warnings[] = sprintf(
                '%s está fuera de lo posible para un bovino (%d a %d kg). Revisá la balanza o el tipeo.',
                self::kg($weight),
                self::MIN_PHYSICAL_KG,
                self::MAX_PHYSICAL_KG
            );
        }

        $dailyChange = $previous !== null ? self::dailyChange($weight, $date, $previous) : null;
        if ($dailyChange !== null && ($dailyChange > self::MAX_DAILY_GAIN_KG || $dailyChange < self::MIN_DAILY_GAIN_KG)) {
            $warnings[] = sprintf(
                'Pesó %s el %s y ahora %s: %s%s kg/día. Revisá la lectura.',
                self::kg($previous->getWeight()),
                $previous->getWeighingDate()->format('d/m/Y'),
                self::kg($weight),
                $dailyChange > 0 ? '+' : '',
                number_format($dailyChange, 1, ',', '.')
            );
        }

        return $warnings;
    }

    /** Another weight of the same day: how a mistake is corrected, so it is asked and not refused. */
    public function sameDayWarning(CaravanWeightEntity $sameDay): string
    {
        return sprintf(
            'Ya hay un pesaje de %s del %s. Si registrás este, aquel queda en el historial.',
            self::kg($sameDay->getWeight()),
            $sameDay->getWeighingDate()->format('d/m/Y')
        );
    }

    /** Kg per day since an earlier weighing; null when no time passed between them. */
    public static function dailyChange(float $weight, string $date, CaravanWeightEntity $previous): ?float
    {
        $days = (int) round(
            ((new \DateTimeImmutable($date))->getTimestamp() - (new \DateTimeImmutable($previous->getWeighingDate()->format('Y-m-d')))->getTimestamp())
            / self::SECONDS_PER_DAY
        );

        return $days > 0 ? ($weight - $previous->getWeight()) / $days : null;
    }

    private static function kg(float $value): string
    {
        $text = number_format($value, 1, ',', '.');

        return (str_ends_with($text, ',0') ? substr($text, 0, -2) : $text) . ' kg';
    }
}
