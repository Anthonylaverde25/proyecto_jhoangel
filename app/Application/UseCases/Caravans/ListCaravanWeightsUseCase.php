<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanWeightRepository;

final class ListCaravanWeightsUseCase
{
    public function __construct(
        private readonly ICaravanWeightRepository $caravanWeightRepository
    ) {
    }

    public function __invoke(int $caravanId): array
    {
        return $this->caravanWeightRepository->findByCaravanId($caravanId);
    }
}
