<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\BullHealthEvaluationEntity;

interface IBullHealthEvaluationRepository
{
    public function save(BullHealthEvaluationEntity $evaluation): BullHealthEvaluationEntity;

    public function findByCaravanId(int $caravanId, int $companyId): ?BullHealthEvaluationEntity;

    /**
     * @return array<BullHealthEvaluationEntity>
     */
    public function findAllBullsWithHealth(int $companyId): array;
}
