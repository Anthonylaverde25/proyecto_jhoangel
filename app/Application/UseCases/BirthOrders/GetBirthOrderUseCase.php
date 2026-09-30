<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Core\Entities\BirthOrderEntity;
use App\Core\Interfaces\IBirthOrderRepository;

final class GetBirthOrderUseCase
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    public function __invoke(int $id, int $companyId): ?BirthOrderEntity
    {
        return $this->repository->findById($id, $companyId);
    }
}
