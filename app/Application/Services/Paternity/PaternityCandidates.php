<?php

declare(strict_types=1);

namespace App\Application\Services\Paternity;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Interfaces\IPaternityRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\ConceptionWindow;

/**
 * The candidate sires of each calf, with the evidence to choose among them: the bulls kept on its
 * gestation at the chute or, for a gestation without them, the bulls of its service order that were
 * with the mother; and whether each one's stay crossed the estimated conception.
 */
final class PaternityCandidates
{
    public function __construct(
        private readonly IPaternityRepository $repository,
        private readonly IServiceOrderRepository $serviceOrderRepository
    ) {
    }

    /**
     * @param list<array<string, mixed>> $calves rows of IPaternityRepository::findCalves
     * @return list<array<string, mixed>> the rows with conception_window and candidates
     */
    public function attach(array $calves, int $companyId): array
    {
        $orderIds = array_values(array_unique(array_filter(array_column($calves, 'service_order_id'))));
        $stays = $orderIds !== [] ? $this->repository->findBullStays($orderIds) : [];
        /** @var array<int, ?ServiceOrderEntity> $orders */
        $orders = [];

        return array_map(function (array $calf) use ($stays, &$orders, $companyId): array {
            $orderId = $calf['service_order_id'];
            $orderStays = $orderId !== null ? ($stays[$orderId] ?? []) : [];
            $window = ConceptionWindow::forBirth($calf['birth_date']);

            $identifications = [];
            foreach ($calf['gestation_sires'] as $sire) {
                $identifications[$sire['id']] = $sire['identification'];
            }
            if ($identifications === [] && $orderId !== null) {
                $orders[$orderId] ??= $this->serviceOrderRepository->findById($orderId, $companyId);
                foreach ($orders[$orderId]?->getSireCandidateIdsFor($calf['mother_id']) ?? [] as $bullId) {
                    $identifications[$bullId] = $orderStays[$bullId]['identification'] ?? (string) $bullId;
                }
            }

            $candidates = [];
            foreach ($identifications as $bullId => $identification) {
                $stay = $orderStays[$bullId] ?? null;
                $candidates[] = [
                    'id' => (int) $bullId,
                    'identification' => $identification,
                    'joined_at' => $stay['joined_at'] ?? null,
                    'retired_at' => $stay['retired_at'] ?? null,
                    // Without his stay in the order there is no date to compare against.
                    'compatible_by_date' => $stay !== null
                        ? ConceptionWindow::overlaps($window, $stay['joined_at'], $stay['retired_at'])
                        : null,
                ];
            }

            unset($calf['gestation_sires']);

            return $calf + ['conception_window' => $window, 'candidates' => $candidates];
        }, $calves);
    }
}
