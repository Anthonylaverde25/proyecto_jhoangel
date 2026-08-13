<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\LineageEntity;
use App\Core\Interfaces\ICaravanLineageRepository;

final class ListPendingSireUseCase
{
    public function __construct(
        private readonly ICaravanLineageRepository $lineageRepository
    ) {
    }

    /**
     * Returns all lineage records with no father assigned.
     * Used to populate the dashboard pending sires widget.
     *
     * @return LineageEntity[]
     */
    public function __invoke(): array
    {
        return $this->lineageRepository->findPendingSire();
    }
}
