<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WeighingTypeEntity;

interface IWeighingTypeRepository
{
    /** @return WeighingTypeEntity[] in the zootechnical order of the cycle */
    public function findAll(bool $onlyActive = false): array;

    public function findById(int $id): ?WeighingTypeEntity;

    public function save(WeighingTypeEntity $type): void;
}
