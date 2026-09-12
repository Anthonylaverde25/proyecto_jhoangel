<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\BullHealthEvaluationEntity;
use App\Core\ValueObjects\VenerealSamplingStatus;

interface IBullHealthEvaluationRepository
{
    public function save(BullHealthEvaluationEntity $evaluation): BullHealthEvaluationEntity;

    public function findByCaravanId(int $caravanId, int $companyId): ?BullHealthEvaluationEntity;

    /**
     * @return array<BullHealthEvaluationEntity>
     */
    public function findAllBullsWithHealth(int $companyId): array;

    /**
     * ADR-4: accumulated venereal sampling of one bull, consumed by BullHealthEvaluationEngine.
     */
    public function findVenerealSampling(int $caravanId, int $companyId): VenerealSamplingStatus;

    /**
     * ADR-10: bulk variant used by the recompute service to avoid an N+1 over the troop.
     *
     * @param list<int> $caravanIds
     * @return array<int, VenerealSamplingStatus> Keyed by caravan id.
     */
    public function findVenerealSamplingForCaravans(array $caravanIds, int $companyId): array;
}
