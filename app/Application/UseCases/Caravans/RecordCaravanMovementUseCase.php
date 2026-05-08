<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\CaravanMovementEntity;
use App\Core\Interfaces\ICaravanMovementRepository;

final class RecordCaravanMovementUseCase
{
    public function __construct(
        private readonly ICaravanMovementRepository $movementRepository
    ) {}

    public function execute(
        int $caravanId,
        string $renspa,
        string $type,
        ?string $observations = null
    ): void {
        $movement = new CaravanMovementEntity(
            null,
            $caravanId,
            $renspa,
            $type,
            new \DateTime(),
            $observations
        );

        $this->movementRepository->save($movement);
    }
}
