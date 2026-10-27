<?php

declare(strict_types=1);

namespace App\Core\Services;

use DateTimeImmutable;

/**
 * When a calf was conceived, estimated back from its birth: a bovine gestation lasts 270 to 295
 * days. A bull whose stay in the service crossed that window is compatible by date; it is evidence
 * for the user, never a confirmed sire.
 */
final class ConceptionWindow
{
    public const MIN_GESTATION_DAYS = 270;
    public const MAX_GESTATION_DAYS = 295;

    /**
     * @return array{from: string, to: string}
     */
    public static function forBirth(string $birthDate): array
    {
        $birth = new DateTimeImmutable(substr($birthDate, 0, 10));

        return [
            'from' => $birth->modify('-' . self::MAX_GESTATION_DAYS . ' days')->format('Y-m-d'),
            'to' => $birth->modify('-' . self::MIN_GESTATION_DAYS . ' days')->format('Y-m-d'),
        ];
    }

    /**
     * @param array{from: string, to: string} $window
     */
    public static function overlaps(array $window, ?string $joinedAt, ?string $retiredAt): bool
    {
        $joined = $joinedAt !== null ? substr($joinedAt, 0, 10) : null;
        $retired = $retiredAt !== null ? substr($retiredAt, 0, 10) : null;

        return ($joined === null || $joined <= $window['to'])
            && ($retired === null || $retired >= $window['from']);
    }
}
