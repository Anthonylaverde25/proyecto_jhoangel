<?php

declare(strict_types=1);

namespace App\Application\UseCases\Farms;

use App\Core\Entities\FarmEntity;
use App\Core\Interfaces\IFarmRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class FindFarmUseCase
{
    public function __construct(
        private readonly IFarmRepository $repository
    ) {
    }

    public function __invoke(int $id): FarmEntity
    {
        $farm = $this->repository->findById($id);

        if (!$farm) {
            throw new ModelNotFoundException("Farm with ID {$id} not found.");
        }

        return $farm;
    }
}
