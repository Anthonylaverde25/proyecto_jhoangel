<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\CastrationStatus;
use App\Core\ValueObjects\CastrationRecord;

final class CastrationPolicy
{
    /**
     * Resolves the castration status of an animal.
     * Returns null for females because castration status does not apply.
     */
    public function status(AnimalSex $sex, ?string $categoryCode, bool $hasActiveCastration): ?CastrationStatus
    {
        if ($sex === AnimalSex::FEMALE) {
            return null;
        }

        $code = strtoupper(trim($categoryCode ?? ''));

        if (in_array($code, ['NOVILLITO', 'NOVILLO'], true)) {
            return CastrationStatus::CASTRATED;
        }

        if (in_array($code, ['TORITO', 'TORO'], true)) {
            return CastrationStatus::INTACT;
        }

        // TERNERO or unassigned category
        if ($hasActiveCastration) {
            return CastrationStatus::CASTRATED;
        }

        return CastrationStatus::NOT_RECORDED;
    }

    /**
     * Explains why the animal cannot be castrated under CS1 and CS2, or returns null if eligible.
     */
    public function refusal(AnimalSex $sex, ?string $categoryCode, ?CastrationRecord $active): ?string
    {
        if ($sex === AnimalSex::FEMALE) {
            return 'sólo se castran machos';
        }

        if ($categoryCode === null || trim($categoryCode) === '') {
            return 'asigná la categoría primero';
        }

        $code = strtoupper(trim($categoryCode));

        if (in_array($code, ['NOVILLITO', 'NOVILLO'], true)) {
            return 'ya es castrado por su categoría';
        }

        if ($code === 'TORO') {
            return 'un toro adulto no se castra desde aquí; si no sirve, se descarta';
        }

        if (!in_array($code, ['TERNERO', 'TORITO'], true)) {
            return 'sólo se castran machos de categoría TERNERO o TORITO';
        }

        if ($active !== null) {
            $formattedDate = date('d/m/Y', strtotime($active->castratedOn));

            return "ya está castrado desde {$formattedDate}";
        }

        return null;
    }

    /**
     * Checks if a target category is permissible for the given castration status (CS9).
     * A castrated animal can never become TORITO or TORO again.
     */
    public function allowsCategory(?CastrationStatus $status, string $targetCategoryCode): bool
    {
        if ($status !== CastrationStatus::CASTRATED) {
            return true;
        }

        $target = strtoupper(trim($targetCategoryCode));

        return !in_array($target, ['TORITO', 'TORO'], true);
    }

    /**
     * Target categories a castrated torito may transition to (CS6). Empty for a calf, which stays TERNERO.
     *
     * @return list<string>
     */
    public function targetsFor(string $categoryCode): array
    {
        $code = strtoupper(trim($categoryCode));

        if ($code === 'TORITO') {
            return ['NOVILLITO', 'NOVILLO'];
        }

        return [];
    }
}

