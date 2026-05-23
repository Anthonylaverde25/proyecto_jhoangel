<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Exceptions\DomainException;

final class WeanCaravanUseCase
{
    public function __construct(
        private readonly ICaravanLineageRepository $lineageRepository
    ) {
    }

    public function __invoke(int $caravanId): void
    {
        $lineage = $this->lineageRepository->findByCaravanId($caravanId);
        if ($lineage === null) {
            throw new DomainException("No se encontró registro de linaje para la caravana especificada.");
        }

        if (!$lineage->isNursing()) {
            throw new DomainException("La caravana ya se encuentra destetada.");
        }

        $this->lineageRepository->wean($caravanId);
    }
}
