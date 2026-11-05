<?php

declare(strict_types=1);

namespace App\Application\UseCases\Castrations;

use App\Core\Entities\CategoryChangeEntity;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICategoryChangeRepository;

final class ListCaravanCategoryChangesUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICategoryChangeRepository $categoryChangeRepository
    ) {
    }

    /**
     * @return list<CategoryChangeEntity>
     */
    public function __invoke(int $companyId, int $caravanId): array
    {
        $caravan = $this->caravanRepository->findById($caravanId);
        if ($caravan === null || $caravan->getCompanyId() !== $companyId) {
            throw new DomainException('La caravana no existe o no pertenece a la empresa.');
        }

        return $this->categoryChangeRepository->listByCaravan($caravanId);
    }
}

