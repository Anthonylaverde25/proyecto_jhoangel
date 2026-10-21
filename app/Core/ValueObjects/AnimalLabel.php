<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\AnimalSex;

/**
 * How an animal is named on screen: its tag or, for a calf still waiting for one, its mother's
 * ("Cría de 4521 ♀"). Never stored: the tag column holds only real tags.
 */
final class AnimalLabel
{
    public static function of(?string $identification, ?string $motherIdentification, AnimalSex|string|null $sex): string
    {
        if ($identification !== null && trim($identification) !== '') {
            return $identification;
        }

        $sexValue = $sex instanceof AnimalSex ? $sex->value : $sex;
        $symbol = match ($sexValue) {
            AnimalSex::MALE->value => ' ♂',
            AnimalSex::FEMALE->value => ' ♀',
            default => '',
        };

        return 'Cría de ' . ($motherIdentification ?? '?') . $symbol;
    }
}
