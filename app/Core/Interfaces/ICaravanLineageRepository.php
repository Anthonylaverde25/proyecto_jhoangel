<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\LineageEntity;

interface ICaravanLineageRepository
{
    public function save(LineageEntity $lineage): LineageEntity;
    
    public function findByCaravanId(int $caravanId): ?LineageEntity;
    
    /**
     * @param int $motherId
     * @return LineageEntity[]
     */
    public function findOffspringByMotherId(int $motherId): array;
    
    public function wean(int $caravanId): void;
}
