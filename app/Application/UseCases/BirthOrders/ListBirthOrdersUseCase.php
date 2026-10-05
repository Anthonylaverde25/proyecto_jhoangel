<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Core\Entities\BirthOrderEntity;
use App\Core\Interfaces\IBirthOrderRepository;

final class ListBirthOrdersUseCase
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    /**
     * @return BirthOrderEntity[]
     */
    public function __invoke(int $companyId, ?string $status = null, ?string $kind = null, bool $overdueOnly = false): array
    {
        return $this->repository->list($companyId, $status, $kind, $overdueOnly);
    }
}
