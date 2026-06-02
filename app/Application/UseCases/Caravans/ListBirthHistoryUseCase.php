<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Entities\BirthHistoryEntity;

final class ListBirthHistoryUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    /**
     * @return BirthHistoryEntity[]
     */
    public function __invoke(): array
    {
        return $this->repository->findBirthHistory();
    }
}
