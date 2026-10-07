<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\ICaravanRepository;

/**
 * One animal's record, as the phone shows it after reading its caravan at the chute.
 *
 * The repository is scoped to the active company, so another company's animal is simply not
 * found: its data belongs to them.
 */
final class GetCaravanUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    public function __invoke(int $id): ?CaravanEntity
    {
        return $this->repository->findById($id);
    }
}
