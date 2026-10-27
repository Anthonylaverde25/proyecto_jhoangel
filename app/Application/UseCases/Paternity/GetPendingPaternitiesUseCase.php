<?php

declare(strict_types=1);

namespace App\Application\UseCases\Paternity;

use App\Application\Services\Paternity\PaternityCandidates;
use App\Core\Interfaces\IPaternityRepository;

/**
 * Calves born without a confirmed sire (an order with more than one bull, or a sire never
 * confirmed), with their candidate sires and the estimated conception, for "Paternidad pendiente".
 */
final class GetPendingPaternitiesUseCase
{
    public function __construct(
        private readonly IPaternityRepository $repository,
        private readonly PaternityCandidates $candidates
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(int $companyId, ?int $serviceOrderId = null, ?string $search = null): array
    {
        $today = date('Y-m-d');
        $calves = $this->candidates->attach($this->repository->findCalves($companyId, $serviceOrderId, $search), $companyId);

        return array_map(static function (array $calf) use ($today): array {
            unset($calf['father_id']);
            $calf['days_pending'] = max(0, (int) floor((strtotime($today) - strtotime($calf['birth_date'])) / 86400));

            return $calf;
        }, $calves);
    }
}
