<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Core\Entities\WeaningOrderEntity;
use App\Core\Interfaces\IWeaningOrderRepository;

final class ListWeaningOrdersUseCase
{
    public function __construct(private readonly IWeaningOrderRepository $repository)
    {
    }

    /**
     * @return WeaningOrderEntity[]
     */
    public function __invoke(int $companyId, ?string $status = null, ?string $kind = null): array
    {
        return $this->repository->list($companyId, $status, $kind);
    }
}
