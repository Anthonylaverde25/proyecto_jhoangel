<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\AnimalCategory;

final class FemaleReproductiveDetails
{
    public function __construct(
        private readonly bool $isEmpty,
        private readonly AnimalCategory $arrivalCategory
    ) {}

    public function isEmpty(): bool
    {
        return $this->isEmpty;
    }

    public function getArrivalCategory(): AnimalCategory
    {
        return $this->arrivalCategory;
    }
}
