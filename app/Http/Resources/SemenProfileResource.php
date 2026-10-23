<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\SemenProfileEntity;
use App\Core\Enums\StrawColor;

/** The straw profile of a semen item (plan 4.2), or null for any other item. */
final class SemenProfileResource
{
    /** @return array<string, mixed>|null */
    public static function of(?SemenProfileEntity $profile): ?array
    {
        if ($profile === null) {
            return null;
        }
        $color = fn (?StrawColor $c) => $c === null ? null : ['value' => $c->value, 'label' => $c->label()];

        return [
            'sire' => $profile->sire === null ? ['id' => $profile->sireId] : SemenSireResource::shape($profile->sire),
            'semen_type' => ['value' => $profile->type->value, 'label' => $profile->type->label()],
            'preservation' => ['value' => $profile->preservation->value, 'label' => $profile->preservation->label()],
            'straw_size' => $profile->strawSize === null ? null : ['value' => $profile->strawSize->value, 'label' => $profile->strawSize->label()],
            'straw_color' => $color($profile->strawColor),
            'cap_color' => $color($profile->capColor),
        ];
    }
}
