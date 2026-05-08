<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\CaravanMovementEntity;
use App\Core\Interfaces\ICaravanMovementRepository;

final class ListCaravanMovementsUseCase
{
    public function __construct(
        private readonly ICaravanMovementRepository $movementRepository
    ) {}

    /**
     * @param int $caravanId
     * @return CaravanMovementEntity[]
     */
    public function execute(int $caravanId): array
    {
        return $this->movementRepository->findByCaravanId($caravanId);
    }
}
