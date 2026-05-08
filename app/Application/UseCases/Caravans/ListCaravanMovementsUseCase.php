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
     * @param int|null $caravanId
     * @return CaravanMovementEntity[]
     */
    public function execute(?int $caravanId = null): array
    {
        if ($caravanId === null) {
            return $this->movementRepository->findAll();
        }
        
        return $this->movementRepository->findByCaravanId($caravanId);
    }
}
